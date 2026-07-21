<?php
namespace Core2;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SlackWebhookHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Processor\WebProcessor;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\MissingExtensionException;
use Monolog\Handler\TelegramBotHandler;

require_once 'Log/DiscordHandler.php';

/**
 * Обеспечение журналирования запросов пользователей и других событий
 */
class Log {

    private $log;
    private $config;
    private $writer;
    private $writer_custom;
    private $handlers;
    private string $date_format = "Y-m-d H:i:s.u";


    /**
     * Log constructor.
     * @param string $name
     * @throws \Exception
     */
    public function __construct($name = 'core2') {

        if ($name !== 'access') {
            //эта секция предназначена для работы ядра
            $this->log = new Logger($_SERVER['SERVER_NAME'] . "." . $name);
            $this->config = Registry::get('core_config');

            if ($name === 'profile') {
                if (isset($this->config->profile->mysql)) {
                    $profile_mysql = strpos($this->config->profile->mysql, '/') !== 0
                        ? __DIR__ . "/../../" . $this->config->profile->mysql
                        : $this->config->profile->mysql;

                    $stream = new StreamHandler($profile_mysql);
                    $this->log->pushHandler($stream);
                    $this->writer = 'file';
                } else {
                    return new \stdClass();
                }

            }
            elseif ($name === 'webhook') {
                if (isset($this->config->log) &&
                    isset($this->config->log->webhook)
                ) {
                    //TODO add more webhooks

                } else {
                    return new \stdClass();
                }
            }
            elseif ($name === 'logger') {
                //Logger worker
                $this->writer = 'file';
            } else {
                $config = Registry::get('config');
                if (isset($config->log) && !$config->log->on) return new \stdClass(); //юзер отключил ведение логов

                $files = [];
                if (isset($this->config->log) &&
                    isset($this->config->log->system) &&
                    ! empty($this->config->log->system->file) &&
                    is_string($this->config->log->system->file)
                ) {
                    $files[] = $this->config->log->system->file;
                }
                if (isset($config->log) &&
                    isset($config->log->system) &&
                    ! empty($config->log->system->file) &&
                    is_string($config->log->system->file)
                ) {
                    if (!in_array($config->log->system->file, $files)) $files[] = $config->log->system->file;
                }

                foreach ($files as $file) {

                    $stream = new StreamHandler($file);
                    //$stream->setFormatter(new NormalizerFormatter());
                    $this->log->pushHandler($stream);
                }
            }
        }
        else {
            $this->config = Registry::get('config');
            if (isset($this->config->log) &&
                isset($this->config->log->access) &&
                ! empty($this->config->log->access->file) &&
                is_string($this->config->log->access->file)
            ) {
                $this->log    = new Logger($name);
                $this->log->pushHandler(new StreamHandler($this->config->log->access->file, Logger::INFO));
                $this->writer = 'file';
            }
        }
    }


    /**
     * Дополнительный лог в заданный файл
     * @param $filename
     * @return $this
     * @throws \Exception
     */
    public function file($filename) {
        if ( ! $this->writer_custom) {
            $this->log->pushHandler(new StreamHandler($filename));
            $this->writer_custom = $filename;
        }
        return $this;
    }


    /**
     * Журнал запросов
     * @param string $name
     */
    public function access($name, $sid) {
        $this->log->pushProcessor(new WebProcessor());
        $this->log->info($name, array('sid' => $sid));
    }


    /**
     * Информационная запись в лог
     * @param array|string $msg
     * @param array        $context
     * @throws MissingExtensionException
     */
    public function info($msg, $context = array()): void {

        if (is_array($msg)) {
            if (!$context) {
                $context = $msg;
                $msg = '-';
            }
            else $msg = json_encode($msg);
        }

        if ($this->handlers) {
            $this->setHandler(Logger::INFO);
        } else {
            $this->subscription(Logger::ERROR);
        }

        try {
            $this->log->info($msg, $context);

        } catch (\Exception $e) {
            $this->clearHandlers();
            $this->setWriter();
            $this->log->error($e->getMessage(), [ 'exception' => $e ]);
        }

        $this->clearHandlers();
        $this->removeCustomWriter();
    }


    /**
     * Предупреждение в лог
     * @param array|string $msg
     * @param array        $context
     */
    public function warning($msg, $context = array()) {
        if (is_array($msg)) {
            if (!$context) {
                $context = $msg;
                $msg = '-';
            }
            else $msg = json_encode($msg);
        }
        if ($this->handlers) {
            $this->setHandler(Logger::WARNING);
        } else {
            $this->subscription(Logger::ERROR);
        }

        try {
            $this->log->warning($msg, $context);

        } catch (\Exception $e) {
            $this->clearHandlers();
            $this->setWriter();
            $this->log->error($e->getMessage(), [ 'exception' => $e ]);
        }

        $this->clearHandlers();
        $this->removeCustomWriter();
    }


    /**
     * Предупреждение в лог
     * @param array|string     $msg
     * @param array|\Exception $context
     * @throws MissingExtensionException
     */
    public function error($msg, $context = array()) {

        if (is_array($msg)) {
            if (!$context) {
                $context = $msg;
                $msg = '-';
            }
            else $msg = json_encode($msg);
        }

        if ($context instanceof \Exception) {
            $context = [
                'message' => $context->getMessage(),
                'file'    => $context->getFile(),
                'line'    => $context->getLine(),
                'trace'   => $context->getTrace(),
            ];
        }

        if ($this->handlers) {
            $this->setHandler(Logger::ERROR);
        } else {
            $this->subscription(Logger::ERROR);
        }

        try {
            $this->log->error($msg, $context);

        } catch (\Exception $e) {
            $this->clearHandlers();
            $this->setWriter();
            $this->log->error($e->getMessage(), [ 'exception' => $e ]);
        }

        $this->clearHandlers();
        $this->removeCustomWriter();
    }


    /**
     * Отладочная информация в лог
     * @param array|string $msg
     * @param array        $context
     * @throws MissingExtensionException
     */
    public function debug($msg, $context = array()) {
        if (is_array($msg)) {
            if (!$context) {
                $context = $msg;
                $msg = '-';
            }
            else $msg = json_encode($msg);
        }
        if ($this->handlers) {
            $this->setHandler(Logger::DEBUG);
        } else {
            $this->subscription(Logger::ERROR);
        }

        try {
            $this->log->debug($msg, $context);

        } catch (\Exception $e) {
            $this->clearHandlers();
            $this->setWriter();
            $this->log->error($e->getMessage(), [ 'exception' => $e ]);
        }

        $this->clearHandlers();
        $this->removeCustomWriter();
    }


    /**
     * @return string
     */
    public function getWriter() {
        return $this->writer;
    }


    /**
     * прекращение записи в заданный дополнительный лог
     */
    private function removeCustomWriter() {
        if ($this->writer_custom) {
            $this->log->popHandler();
            $this->writer_custom = false;
        }
    }


    /**
     * Куда писать журнал запросов
     */
    private function setWriter() {
        if ( ! $this->writer) {
            if (isset($this->config->log) &&
                isset($this->config->log->system) &&
                ! empty($this->config->log->system->file) &&
                is_string($this->config->log->system->file)
            ) {
                $this->log->pushHandler(new StreamHandler($this->config->log->system->file, Logger::INFO));
                $this->writer = 'file';
            } else {
                $this->log->pushHandler(new SyslogHandler($_SERVER['SERVER_NAME'] . ".core2"));
                $this->writer = 'syslog';
            }
        }
    }


    /**
     * Удаление заданных ранее дополнительных обработчиков
     * @return void
     */
    private function clearHandlers(): void {

        $this->handlers = [];
        $this->log->setHandlers([]);
    }


    /**
     * Установка обработчика
     * @param int $level уровень журналирования
     * @throws MissingExtensionException
     */
    private function setHandler($level): void {

        while ($this->log->getHandlers()) {
            $this->log->popHandler();
        }

        foreach ($this->handlers as $name => $params) {
            if ($name == 'slack') {
                $handler = new SlackWebhookHandler($params[0], $params[1], $params[2], $params[3], $params[4], $params[5], $params[6], $level);
                $this->log->pushHandler($handler);
            }
        }
    }


    /**
     * Отправка сообщения в Slack
     * @return self|\stdClass
     * @throws MissingExtensionException
     */
    public function slack(): \stdClass|self {

        if ( ! $this->config?->log?->webhook?->slack?->url ||
             ! is_string($this->config->log->webhook->slack->url)
        ) {
            return new \stdClass();
        }


        $handler = new SlackWebhookHandler($this->config->log->webhook->slack->url);
        $handler->setFormatter(new LineFormatter(null, $this->date_format));

        $this->log->pushHandler($handler);

        return $this;
    }


    /**
     * Отправка сообщения в Tg
     * @return self|\stdClass
     * @throws MissingExtensionException
     */
    public function telegram(): \stdClass|self {

        if ( ! $this->config?->log?->webhook?->telegram?->apikey ||
             ! $this->config?->log?->webhook?->telegram?->channels ||
             ! is_string($this->config->log->webhook->telegram->apikey) ||
             ! is_string($this->config->log->webhook->telegram->channels)
        ) {
            return new \stdClass();
        }

        $channels = explode(',', $this->config->log->webhook->telegram->channels);
        $channels = array_map('trim', $channels);
        $channels = array_filter($channels);

        if (empty($channels)) {
            return new \stdClass();
        }

        foreach ($channels as $channel) {
            $handler = new TelegramBotHandler($this->config->log->webhook->telegram->apikey, $channel);
            $handler->setFormatter(new LineFormatter(null, $this->date_format));

            $this->log->pushHandler($handler);
        }

        return $this;
    }


    /**
     * Отправка сообщения в Slack
     * @return self|\stdClass
     */
    public function discord(): \stdClass|self {

        if ( ! $this->config?->log?->webhook?->discord?->url ||
             ! is_string($this->config->log->webhook->discord->url)
        ) {
            return new \stdClass();
        }


        $handler = new Log\DiscordHandler($this->config->log->webhook->discord->url);
        $handler->setFormatter(new LineFormatter(null, $this->date_format));
        $this->log->pushHandler($handler);

        return $this;
    }


    /**
     * Подписка на события
     * @param int $level
     * @return void
     * @throws MissingExtensionException
     */
    private function subscription(int $level): void {

        if ($this->config?->log?->subscribe?->level &&
            $this->config?->log?->subscribe?->recipients &&
            is_string($this->config->log->subscribe->level) &&
            is_string($this->config->log->subscribe->recipients)
        ) {
            $subscribe_levels = explode(',', $this->config->log->subscribe->level);
            $subscribe_levels = array_map('trim', $subscribe_levels);

            if ( ! in_array('all', $subscribe_levels)) {
                if ($level === Logger::ERROR && ! in_array('error', $subscribe_levels)) {
                    return;

                } elseif ($level === Logger::WARNING && ! in_array('warning', $subscribe_levels)) {
                    return;

                } elseif ($level === Logger::INFO && ! in_array('info', $subscribe_levels)) {
                    return;

                } elseif ($level === Logger::DEBUG && ! in_array('debug', $subscribe_levels)) {
                    return;
                }
            }


            $recipients = explode(',', $this->config->log->subscribe->recipients);
            $recipients = array_map('trim', $recipients);
            $recipients = array_filter($recipients);

            if ( ! empty($recipients)) {
                if (in_array('slack', $recipients)) {
                    $this->slack();
                }

                if (in_array('telegram', $recipients)) {
                    $this->telegram();
                }

                if (in_array('discord', $recipients)) {
                    $this->discord();
                }
            }
        }
    }
}