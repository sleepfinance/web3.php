<?php
namespace Web3\Contracts;
use Web3\Utils;
use BCMathExtended\BC;
use Web3\Contracts\Ethabi;

class TxReceipt
{
    public function __construct(
        public object $tx, 
        public array $events,
        public Ethabi $ethabi
    ){}
    /**
     * Should be used to decode indexed params and options
     *
     * @method _decodeEventABI
     * @param object $data
     * @return object result object with decoded indexed && not indexed params
     * */

    public function decode()
    {
        $data = $this->tx;
        $data->data = $data->data??null;
        $data->topics = $data->topics??[];
        $result = self::outputTxFormatter($data);
        $logs = collect($result->logs)->map(function($log){
            $signature = $log->topics[0];
            //return unknown logs as is
            if(!in_array($signature, array_keys($this->events))) return $log;
            $event = $this->events[$signature];
            $log = self::outputLogFormatter($log);
            $indexedInputs =  collect($event['inputs'])->reject(function ($input) {
                return !$input['indexed'];
            })->count();
            if ($indexedInputs > 0 && (count($log->topics) !== $indexedInputs + 1)) {
                throw (new \Exception("Event {$event['name']} signatures dont match"));
            }
            $argTopics = array_slice($log->topics, 1);
            $log->returnValues = $this->decodeLog($event['inputs'], $log->data, $argTopics);
            $log->event = $event['name'];
            $log->signature = $signature;
            $log->raw = [
                'data' => $log->data,
                'topics' => $log->topics
            ];
            return $log;
        })->all();
        $result->logs = $logs;
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
        $log->id = Utils::sha3(base64_encode(random_bytes(10)));
        if (isset($log->blockNumber))
            $log->blockNumber = Bc::hexdec($log->blockNumber);
        if (isset($log->transactionIndex))
            $log->transactionIndex = Bc::hexdec($log->transactionIndex);
        if (isset($log->logIndex))
            $log->logIndex = Bc::hexdec($log->logIndex);
        if (isset($log->address))
            $log->address = Utils::toChecksumAddress($log->address);
        return $log;
      
    }

    /**
     * Formats the output of a log
     *
     * @method outputLogFormatter
     * @param object log object
     * @returns object log
     */
    public static function outputTxFormatter($log)
    {
        if (isset($log->blockNumber))
            $log->blockNumber = Bc::hexdec($log->blockNumber);
        if (isset($log->transactionIndex))
            $log->transactionIndex = Bc::hexdec($log->transactionIndex);
        if (isset($log->logIndex))
            $log->logIndex = Bc::hexdec($log->logIndex);
        if (isset($log->address))
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
        $abi = $this->ethabi;
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
