<?php

namespace App\Telegram;

use App\Support\BoundedJsonResponse;
use GuzzleHttp\Handler\StreamHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use LengthException;
use LogicException;
use UnexpectedValueException;

class TelegramClient
{
    public function getUpdates(int $offset, ?int $timeout = null): array
    {
        $timeout = max(0, min(50, $timeout ?? (int) config('telegram.poll_timeout', 25)));
        $result = $this->request('getUpdates', [
            'offset' => $offset, 'timeout' => $timeout, 'limit' => 100,
            'allowed_updates' => ['message'],
        ], $timeout + $this->connectTimeout() + 10);
        if (! is_array($result) || ! array_is_list($result) || count($result) > 100) {
            throw new TelegramException(0);
        }

        return $result;
    }

    public function sendMessage(string $chatId, #[\SensitiveParameter] string $text): string
    {
        $result = $this->request('sendMessage', [
            'chat_id' => $chatId, 'text' => $text,
            'link_preview_options' => ['is_disabled' => true],
        ], max($this->connectTimeout() + 10, (int) config('telegram.request_timeout', 15)));
        if (! is_array($result) || ! isset($result['message_id'])
            || ! preg_match('/^[1-9][0-9]*$/D', (string) $result['message_id'])
            || (isset($result['chat']['id']) && (string) $result['chat']['id'] !== $chatId)) {
            throw new TelegramException(0, ambiguous: true);
        }

        return (string) $result['message_id'];
    }

    private function request(string $method, #[\SensitiveParameter] array $payload, int $timeout): mixed
    {
        $token = config('telegram.token');
        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9:_.-]{1,512}$/D', $token)) {
            throw new LogicException('Telegram bot credentials are not configured.');
        }
        if (! filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw new LogicException('Telegram requests require PHP CLI URL streaming.');
        }
        $timeout = max(1, min(300, $timeout));
        try {
            $response = Http::connectTimeout($this->connectTimeout())->timeout($timeout)
                ->setHandler(new StreamHandler)
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => $timeout])->asJson()
                ->post(rtrim((string) config('telegram.api_url'), '/').'/bot'.$token.'/'.$method, $payload);
        } catch (ConnectionException) {
            throw new TelegramException(0, ambiguous: $method === 'sendMessage');
        }
        try {
            $maximumBytes = max(1024, min(8 * 1024 * 1024, (int) config('telegram.max_response_bytes', 2 * 1024 * 1024)));
            $body = BoundedJsonResponse::decode($response, $maximumBytes, $timeout);
        } catch (LengthException|JsonException|UnexpectedValueException) {
            $status = $response->successful() ? 0 : $response->status();
            throw new TelegramException($status, ambiguous: $method === 'sendMessage' && ($status === 0 || $status >= 500));
        }
        if (! $response->successful() || ! is_array($body) || ($body['ok'] ?? null) !== true) {
            $status = is_array($body) && is_int($body['error_code'] ?? null)
                ? $body['error_code'] : $response->status();
            if ($response->successful() && (! is_array($body) || ! isset($body['error_code']))) {
                $status = 0;
            }
            $retry = is_array($body) ? ($body['parameters']['retry_after'] ?? null) : null;
            throw new TelegramException(
                $status,
                is_int($retry) && $retry > 0 && $retry <= 2147483647 ? $retry : null,
                $method === 'sendMessage' && ($status === 0 || $status >= 500),
            );
        }
        if (! array_key_exists('result', $body)) {
            throw new TelegramException(0, ambiguous: $method === 'sendMessage');
        }

        return $body['result'];
    }

    private function connectTimeout(): int
    {
        return max(1, min(60, (int) config('telegram.connect_timeout', 15)));
    }
}
