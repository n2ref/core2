<?php
namespace Core2;

require_once __DIR__ . '/../inc/classes/Error.php';
require_once __DIR__ . '/../inc/classes/Emitter.php';
require_once __DIR__ . '/../inc/classes/RedisStreamQueue.php';

use Predis\Client;

class Eventer
{

    private $_config;
    private $client;
    private $queue;
    private $_emitter;

    public function __construct()
    {
        $this->_config = Registry::get('config');
        $core_config = Registry::get('core_config');
        // Инициализация Redis клиента
        $this->client = new Client([
            'host' => $core_config->cache->options->server->host,
            'port' => 6379,
            'password' => $core_config->cache->options->server->password,
            'prefix' => $_SERVER['SERVER_NAME'] . ":Core2:Eventer"
        ]);
        // Создание очереди
        $this->queue = new RedisStreamQueue($this->client, prefix: 'core2_queue');
        if (!defined("DOC_ROOT")) {
            define("DOC_ROOT", dirname(dirname(str_replace("//", "/", $_SERVER['SCRIPT_FILENAME']))) . "/");
        }
        if (!defined("DOC_PATH")) {
            define("DOC_PATH", substr(DOC_ROOT, strlen(rtrim($_SERVER['DOCUMENT_ROOT'], '/'))) ? : '/');
        }
        $this->_emitter = new Emitter();
    }


    /**
     * @param \GearmanJob|Job $job
     * @param array       $log
     */
    public function run(\GearmanJob|Job $job, array &$log) {

        $workload = json_decode($job->workload());

        if (\JSON_ERROR_NONE !== json_last_error()) {
            throw new \InvalidArgumentException(json_last_error_msg());
        }
        $_SERVER = get_object_vars($workload->server);

        $context = $workload->payload->context;
        $event  = $workload->payload->event;
        $data   = is_object($workload->payload->data) ? get_object_vars($workload->payload->data) : $workload->payload->data;

        // Добавить сообщение в очередь
        $this->queue->push([
            'event' => $event,
            'data' => $data,
        ], $context);

        $this->_emitter->sync($context, $event, $data);
    }
}