<?php

namespace Web3\Contracts;

use Web3\Contract;
use Web3\Formatters\OptionalQuantityFormatter as BlockFormater;
use Web3\Utils;
use BCMathExtended\BC;
use Illuminate\Support\Str;

class Event
{


    /**
     * Event Abi array.
     *
     * @var array
     */
    protected $event;

    /**
     * Name of returned Event
     *
     * @var string
     */
    public $name;
    /**
     * Rpc Error
     *
     * @var \Exception
     */
    public $error;
    /**
     * Returned Event data
     *
     * @var array|object
     */
    public $data;
    /**
     * Signature of event. 
     *
     * @var string|null
     */
    protected string $eventSignature;
    /**
     * SignatuES of allevents event. 
     *
     * @var array|null
     */
    protected array $eventSignatures;

    /**
     * The event uuid.
     * eg used to track chainids
     * @var string
     */
    public string $uuid;
    /**
     * Contructor.
     *
     * @param \Web3\Contract  $contract
     * @param string $eventName
     * @param array $options
     */
    public function __construct(
        /**
         * The Contract being queried.
         *
         * @var \Web3\Contract
         */
        public Contract $contract,
        /**
         * The name of the requested event.
         * eg Transfer or allEvents for all events
         * @var string
         */
        public string $eventName,
        /**
         * The event filter options.
         * eg Transfer or allEvents for all events
         * @var array
         */
        public array $options,

    ) {
        if ($this->eventName == 'ALLEVENTS') {
            $this->eventSignatures = $this->contract->events ;
        } else {
            $this->event = collect($this->contract->events)->first(fn ($ev) => $ev['name'] === $this->eventName);
            if (!$this->event && $this->eventName !== 'ALLEVENTS')
                throw (new \Exception('Event Doesnt Exist on Contract'));
            $this->eventSignature = $this->contract->getEthabi()->encodeEventSignature($this->event);
        }
    }


    /**
     * set the event data;
     *
     * @return void
     */
    public function getOptions()
    {
        return $this->_encodeABI();
    }

    /**
     * set the event data;
     *
     * @param  callable $callback
     * @return void
     */
    public function getPastLogs(callable $callback)
    {
        $eth = $this->contract->getEth();
        $abi = $this->_encodeABI();
        $eth->getLogs($abi, function ($error, $results) use ($callback) {
            if ($error) return call_user_func($callback, $error, $results);
            $response = collect($results ?? [])->map(function ($result) {
                return  $result ? $this->_decodeABI($result) : $result;
            });
            call_user_func($callback, null, $response);
        });
    }

    /**
     * set the event data;
     *
     * @param  callable $callback
     * @return void
     */
    public function getTxLog(string $hash, callable $callback)
    {
        $eth = $this->contract->getEth();
        $eth->getTransactionReceipt($hash, function ($error, $result) use ($callback) {
            if ($error) return call_user_func($callback, $error, null);
            $response = $result ? (new TxReceipt($result, $this->event, $this->contract->getEthabi()))->decode() : $result;
            call_user_func($callback, null, $response);
        });
    }



    /**
     * Should be used to encode indexed params and options to one final object
     *
     * @method _encodeEventABI
     * @param bool $subscribe 
     * @return object everything combined together and encoded
     */
    protected  function _encodeABI($subscribe = false)
    {
        $options = $this->options;
        $filter = $options['filter'] ?? [];
        $results = [];
        if (isset($options['fromBlock'])) $results['fromBlock'] = BlockFormater::format($options['fromBlock']);
        if (isset($options['toBlock'])) $results['toBlock'] = BlockFormater::format($options['toBlock']);
        if ($subscribe && isset($results['toBlock'])) {
            unset($results['toBlock']);
        }
        if ($this->contract->getToAddress()) {
            $results['address'] = $this->contract->getToAddress();
        }
        if (is_array($options['topics'] ?? null)) {
            $results['topics'] = $options['topics'];
            return $results;
        }
        $topics = [];
        if ($this->eventName == 'ALLEVENTS') return $results;
        // add event signature
        if ($this->eventSignature && !$this->event['anonymous']) {
            $topics[] = $this->eventSignature;
        }

        $indexedTopics = collect($this->event['inputs'])->filter(function ($i) {
            return $i['indexed'] === true;
        })->map(function ($i) use ($filter) {
            $abi = $this->contract->getEthabi();
            $value = $filter[$i['name']] ?? null;
            if (!$value) {
                return null;
            }
            if (is_array($value)) {
                return collect($value)->map(function ($v) use ($abi, $i) {
                    return $abi->encodeParameter($i['type'], $v);
                })->all();
            }
            return $abi->encodeParameter($i['type'], $value);
        })->all();
        $topics = array_merge($topics, $indexedTopics);
        $results['topics'] = $topics;
        return $results;
    }


    /**
     * Should be used to decode indexed params and options
     *
     * @method _decodeEventABI
     * @param object $data
     * @return object result object with decoded indexed && not indexed params
     * */

    protected function _decodeABI($data)
    {
        $data->data = $data->data ?? null;
        $data->topics = $data->topics ?? [];
        $result = self::outputLogFormatter($data);
        $eventSig = $data->topics[0];
        $event = $this->eventName !== 'ALLEVENTS' ? $this->event : $this->eventSignatures[$eventSig];
        $indexedInputs =  collect($event['inputs'])->reject(function ($input) {
            return !$input['indexed'];
        })->count();
        if ($indexedInputs > 0 && (count($data->topics) !== $indexedInputs + 1)) {
            throw (new \Exception('Event results dont match'));
        }
        $argTopics = array_slice($data->topics, 1);
        $result->returnValues = $this->decodeLog($event['inputs'], $data->data, $argTopics);
        $result->event = $event['name'];
        $result->signature = $data->topics[0] ?? null;
        $result->raw = [
            'data' => $data->data,
            'topics' => $data->topics
        ];
        unset($result->data);
        unset($result->topics);
        return $result;
    }

    /**
     * Formats the output of a log
     *
     * @method outputLogFormatter
     * @param object log object
     * @returns object log
     */
    public static function outputLogFormatter($log)
    {
        // generate a custom log id
        $log->id = Str::random();
        if (!is_null($log->blockNumber))
            $log->blockNumber = Bc::hexdec($log->blockNumber);
        if (!is_null($log->transactionIndex))
            $log->transactionIndex = Bc::hexdec($log->transactionIndex);
        if (!is_null($log->logIndex))
            $log->logIndex = Bc::hexdec($log->logIndex);
        if (!is_null($log->address))
            $log->address = Utils::toChecksumAddress($log->address);
        return $log;
    }

    /**
     * Decodes events non- and indexed parameters.
     *
     * @method decodeLog
     * @param array inputs
     * @param string data
     * @param array topics
     * @return array array of plain params
     */
    public function decodeLog($inputs, $data, $topics)
    {
        $topics = is_array($topics) ? $topics : [$topics];
        $data = $data ?? '';
        $nonIndexedInputs = [];
        $indexedParams = [];
        $topicCount = 0;
        $abi = $this->contract->getEthabi();
        foreach ($inputs as $i => $input) {
            if ($input['indexed']) {
                $validTypes = ['address', 'bool', 'bytes', 'dynamicBytes', 'int', 'string', 'uint',];
                preg_match('/^([a-zA-Z]+)/', $input['type'], $match);
                $indexedParams[$input['name']] = in_array($match[0], $validTypes)
                    ? $abi->decodeParameter($input['type'], $topics[$topicCount])
                    : $topics[$topicCount];
                $topicCount++;
            } else {
                $nonIndexedInputs[$input['name']] = $input['type'];
            }
        }
        $notIndexedParams = ($data) ? $abi->decodeParameters(array_values($nonIndexedInputs), $data) : [];
        $names = array_keys($nonIndexedInputs);
        $results = array_combine($names, $notIndexedParams);
        return (object)array_merge($results, $indexedParams);
    }
}
