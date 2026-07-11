<?php

namespace Core2\Log;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class DiscordHandler extends AbstractProcessingHandler {

    private string          $webhook;
    private ClientInterface $client;

    /**
     * Цвета для уровней логирования Discord.
     */
    private array $levelColors = [
        Logger::DEBUG     => 10181046,
        Logger::INFO      => 3447003,
        Logger::NOTICE    => 1752220,
        Logger::WARNING   => 15105570,
        Logger::ERROR     => 16007990,
        Logger::CRITICAL  => 16007990,
        Logger::ALERT     => 16007990,
        Logger::EMERGENCY => 16007990,
    ];


    /**
     * @param string               $webhook
     * @param int                  $level
     * @param bool                 $bubble
     * @param ClientInterface|null $client
     */
    public function __construct(
        string           $webhook,
        int              $level = Logger::DEBUG,
        bool             $bubble = true,
        ?ClientInterface $client = null
    ) {
        parent::__construct($level, $bubble);

        $this->webhook = $webhook;
        $this->client  = $client ?? new Client();
    }


    /**
     * @throws GuzzleException
     */
    protected function write(array $record): void {

        $payload = [
            'embeds' => [
                [
                    'title'       => "{$record['level_name']}: {$record['message']}",
                    "description" => $record['datetime']->format('Y-m-d H:i:s'),
                    'color'       => $this->levelColors[$record['level']] ?? 16007990,
                ],
            ],
        ];

        $multipart = [
            [
                'name'     => 'payload_json',
                'contents' => json_encode($payload),
            ]
        ];

        // Если есть контекст — создаем из него виртуальный файл и прикрепляем
        if ( ! empty($record['context'])) {
            $multipart[] = [
                'name'     => 'files[0]',
                'filename' => 'context.json',
                'contents' => $this->json($record['context']),
            ];
        }

        $this->client->post($this->webhook, [
            'multipart' => $multipart,
        ]);
    }


    /**
     * @param array $data
     * @return string
     */
    private function json(array $data): string {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) ?: '{}';
    }
}