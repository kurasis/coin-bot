<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\Response;
use JsonException;
use LengthException;
use Throwable;
use UnexpectedValueException;

/** Read decoded response bytes without casting or draining an untrusted stream. */
final class BoundedJsonResponse
{
    private const MAXIMUM_JSON_TOKENS = 100000;

    public static function decode(#[\SensitiveParameter] Response $response, int $maximumBytes, int $timeoutSeconds): mixed
    {
        $deadline = hrtime(true) + max(1, min(300, $timeoutSeconds)) * 1_000_000_000;
        try {
            $body = $response->toPsrResponse()->getBody();
            $length = trim($response->header('Content-Length') ?? '');
            $expectedLength = null;
            if ($length !== '' && ctype_digit($length)) {
                $length = ltrim($length, '0') ?: '0';
                $maximum = (string) $maximumBytes;
                if (strlen($length) > strlen($maximum) || (strlen($length) === strlen($maximum) && strcmp($length, $maximum) > 0)) {
                    throw new LengthException('Upstream response exceeds the byte limit.');
                }
                $expectedLength = (int) $length;
            }
            $contents = '';
            while (! $body->eof()) {
                if (hrtime(true) >= $deadline) {
                    throw new UnexpectedValueException('Upstream response read timed out.');
                }
                try {
                    $chunk = $body->read(min(8192, $maximumBytes - strlen($contents) + 1));
                } catch (Throwable) {
                    throw new UnexpectedValueException('Upstream response read failed.');
                }
                if ($chunk === '' && ! $body->eof()) {
                    throw new UnexpectedValueException('Upstream response read stalled.');
                }
                $contents .= $chunk;
                if (strlen($contents) > $maximumBytes) {
                    throw new LengthException('Upstream response exceeds the byte limit.');
                }
            }
            if ($expectedLength !== null && strlen($contents) !== $expectedLength) {
                throw new LengthException('Upstream response was truncated.');
            }
            if (hrtime(true) >= $deadline) {
                throw new UnexpectedValueException('Upstream response read timed out.');
            }
            self::checkStructure($contents, $deadline);

            return json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (LengthException|JsonException|UnexpectedValueException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new UnexpectedValueException('Upstream response read failed.');
        } finally {
            self::close($response);
        }
    }

    /** Bound decoder allocations before JSON creates arrays, keys, and values. */
    private static function checkStructure(#[\SensitiveParameter] string $contents, int $deadline): void
    {
        $inString = false;
        $escaped = false;
        $tokens = 0;
        $length = strlen($contents);
        for ($position = 0; $position < $length; $position++) {
            if (($position & 4095) === 0 && hrtime(true) >= $deadline) {
                throw new UnexpectedValueException('Upstream response validation timed out.');
            }
            $character = $contents[$position];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($character === '"') {
                $inString = true;
                $tokens++;
            } elseif ($character === '[' || $character === '{' || $character === ',' || $character === ':') {
                $tokens++;
            }
            if ($tokens > self::MAXIMUM_JSON_TOKENS) {
                throw new LengthException('Upstream response exceeds the JSON structure limit.');
            }
        }
    }

    public static function close(#[\SensitiveParameter] Response $response): void
    {
        try {
            $response->close();
        } catch (Throwable) {
            // Cleanup cannot propagate upstream exception text or mask the domain result.
        }
    }
}
