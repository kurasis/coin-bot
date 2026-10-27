<?php

declare(strict_types=1);

namespace App\Blockchain;

use App\Blockchain\Exceptions\RangeLimitException;
use App\Blockchain\Exceptions\RpcException;
use App\Operations\ApiPolicy;
use App\Operations\ApiUsage;
use App\Support\BoundedJsonResponse;
use GuzzleHttp\Handler\StreamHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use LengthException;
use UnexpectedValueException;

final class RpcTransport
{
    private int $id = 0;

    public function __construct(
        #[\SensitiveParameter] private readonly array $settings,
        private readonly ?string $chain = null,
        private readonly string $provider = 'primary',
    ) {}

    public function rpc(string $method, array $params): mixed
    {
        $id = ++$this->id;
        $body = $this->post($this->url('rpc_url'), ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => $id]);
        if (($body['jsonrpc'] ?? null) !== '2.0' || ! isset($body['id']) || (! is_int($body['id']) && ! is_string($body['id'])) || (string) $body['id'] !== (string) $id) {
            throw new RpcException('Invalid JSON-RPC response envelope.');
        }
        if (! array_key_exists('result', $body)) {
            throw new RpcException('JSON-RPC response has no result.');
        }

        return $body['result'];
    }

    public function native(string $path, array $params = []): array
    {
        if (! preg_match('#^/wallet(?:solidity)?/[a-z]+$#D', $path)) {
            throw new RpcException('Invalid native blockchain endpoint.');
        }

        return $this->post(rtrim($this->url('native_url'), '/').$path, $params, $path);
    }

    private function url(string $name): string
    {
        $url = $this->settings[$name] ?? null;
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null) {
            throw new RpcException('A valid HTTPS blockchain endpoint is required.');
        }

        return $url;
    }

    private function retryAfterSeconds(string $header): ?int
    {
        $header = trim($header);
        if (ctype_digit($header)) {
            $digits = ltrim($header, '0');
            $digits = $digits === '' ? '0' : $digits;
            $maximum = (string) PHP_INT_MAX;
            if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
                throw new RpcException('Retry-After exceeds the representable scheduler interval.');
            }

            return (int) $digits;
        }
        $date = strtotime($header);

        return $date === false ? null : max(0, $date - time());
    }

    private function post(#[\SensitiveParameter] string $url, array $payload, ?string $operation = null): array
    {
        if (! filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw new RpcException('Blockchain requests require PHP CLI URL streaming.');
        }
        $caBundle = $this->settings['ca_bundle'] ?? (getenv('SSL_CERT_FILE') ?: null);
        if ($caBundle !== null && (! is_string($caBundle) || ! is_readable($caBundle))) {
            throw new RpcException('Configured blockchain TLS CA bundle is not readable.');
        }
        $attempts = max(1, min(5, (int) ($this->settings['attempts'] ?? 3)));
        $timeout = max(1, min(300, (int) ($this->settings['timeout'] ?? 20)));
        $maximumBytes = max(1024, min(32 * 1024 * 1024, (int) ($this->settings['max_response_bytes'] ?? 8 * 1024 * 1024)));
        $policy = $this->chain !== null && config('operations.api_usage_enabled', true)
            ? ApiPolicy::from($this->settings, $this->chain, $this->provider) : null;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($policy !== null) {
                app(ApiUsage::class)->admit($policy, $operation ?? $payload['method'] ?? 'other');
            }
            $retryAfterSeconds = null;
            try {
                $response = Http::acceptJson()->asJson()
                    ->setHandler(new StreamHandler)
                    ->withOptions(['verify' => $caBundle ?? true, 'allow_redirects' => false,
                        'stream' => true, 'read_timeout' => $timeout])
                    ->connectTimeout(max(1, min(60, (int) ($this->settings['connect_timeout'] ?? 15))))
                    ->timeout($timeout)
                    ->withHeaders($this->settings['headers'] ?? [])
                    ->withBody(json_encode($payload === [] ? new \stdClass : $payload, JSON_THROW_ON_ERROR), 'application/json')
                    ->send('POST', $url);
                if (! $response->successful()) {
                    $status = $response->status();
                    $retryHeader = $response->header('Retry-After');
                    // Some gateways use HTTP errors for eth_getLogs query limits.
                    // Split only log queries, never authentication or arbitrary 400s.
                    if (($payload['method'] ?? null) === 'eth_getLogs' && $status === 413) {
                        BoundedJsonResponse::close($response);
                        $retryAfterSeconds = is_string($retryHeader) ? $this->retryAfterSeconds($retryHeader) : null;
                        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
                            // A temporary gateway rejection can require a wait
                            // before any smaller query is allowed as well.
                            throw new RpcException('Blockchain HTTP request failed.', true, $status, $retryAfterSeconds);
                        }
                        throw new QueryPayloadLimitException;
                    }
                    $limited = false;
                    if (($payload['method'] ?? null) === 'eth_getLogs' && $status === 400) {
                        try {
                            $failure = BoundedJsonResponse::decode($response, $maximumBytes, $timeout);
                            $upstream = is_array($failure) ? ($failure['error'] ?? null) : null;
                            $limited = is_array($upstream) && $this->queryLimited($upstream['code'] ?? null, $upstream['message'] ?? null);
                        } catch (JsonException|LengthException|UnexpectedValueException) {
                            // Malformed/oversized HTTP errors remain HTTP errors.
                        } finally {
                            BoundedJsonResponse::close($response);
                        }
                    } else {
                        BoundedJsonResponse::close($response);
                    }
                    if ($limited) {
                        throw new RangeLimitException;
                    }
                    if (is_string($retryHeader)) {
                        $retryAfterSeconds = $this->retryAfterSeconds($retryHeader);
                    }
                    throw new RpcException('Blockchain HTTP request failed.', $status === 408 || $status === 429 || $status >= 500, $status, $retryAfterSeconds);
                }
                try {
                    $body = BoundedJsonResponse::decode($response, $maximumBytes, $timeout);
                } catch (LengthException) {
                    throw new RangeLimitException;
                } catch (JsonException) {
                    throw new RpcException('Blockchain response is not valid bounded JSON.');
                } catch (UnexpectedValueException) {
                    throw new RpcException('Blockchain response could not be safely read.', true);
                }
                if (! is_array($body)) {
                    throw new RpcException('Blockchain response is not a JSON object or array.');
                }
                if (isset($body['error']) || isset($body['Error']) || ($body['success'] ?? true) === false) {
                    $upstream = $body['error'] ?? $body['Error'] ?? '';
                    $message = is_array($upstream) ? ($upstream['message'] ?? '') : $upstream;
                    $code = is_array($upstream) ? ($upstream['code'] ?? null) : null;
                    if (($body['statusCode'] ?? null) === 429 || (is_string($message) && preg_match('/rate.?limit|frequency limit|too many requests/i', $message))) {
                        throw new RpcException('Blockchain endpoint rate limit exceeded.', true, 429);
                    }
                    if ($this->queryLimited($code, $message)) {
                        throw new RangeLimitException;
                    }
                    throw new RpcException('Blockchain endpoint reported an error.', in_array($code, [-32002, -32603], true));
                }

                return $body;
            } catch (ConnectionException) {
                $error = new RpcException('Blockchain connection failed.', true);
            } catch (RpcException $exception) {
                $error = $exception;
            }
            if ($policy !== null) {
                app(ApiUsage::class)->failed($policy);
            }
            if (! $error->retryable || $attempt + 1 >= $attempts) {
                throw $error;
            }
            $maximumDelay = max(0, min(5000, (int) ($this->settings['max_backoff_ms'] ?? 1000)));
            if ($retryAfterSeconds !== null && $retryAfterSeconds > intdiv($maximumDelay, 1000)) {
                throw $error; // Let the supervised worker reschedule a long server-requested delay.
            }
            $base = max(0, (int) ($this->settings['backoff_ms'] ?? 125));
            $delay = $retryAfterSeconds !== null ? $retryAfterSeconds * 1000 : min($maximumDelay, $base * (2 ** $attempt) + ($base > 0 ? random_int(0, $base) : 0));
            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }

        throw new RpcException('Blockchain request failed.');
    }

    private function queryLimited(mixed $code, mixed $message): bool
    {
        return $code === -32005 || (is_string($message) && preg_match('/block.?range|too many (?:results|logs)|response size|query returned more|range.*limit|too many (?:addresses|topics)|(?:request|payload|entity) (?:size|too large)/i', $message) === 1);
    }
}
