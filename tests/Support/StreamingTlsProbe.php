<?php

declare(strict_types=1);
use App\Blockchain\Exceptions\RangeLimitException;
use App\Blockchain\Exceptions\RpcException;
use App\Blockchain\RpcTransport;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Real RpcTransport TLS probe. Run only in a disposable PHP 8.5 container with
 * --network none and a read-only checkout. Certificates/keys stay in private
 * temporary storage; no application bootstrap, .env, credentials, or DB is read.
 */
function serveAuditTls(string $directory): void
{
    umask(0077);
    $context = stream_context_create(['ssl' => [
        'local_cert' => $directory.'/trusted.crt', 'local_pk' => $directory.'/trusted.key',
    ]]);
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    if ($server === false) {
        exit(2);
    }
    $address = stream_socket_get_name($server, false);
    fwrite(STDOUT, substr($address, strrpos($address, ':') + 1)."\n");
    fflush(STDOUT);
    $stats = ['paths' => [], 'tls_rejected' => 0];
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $client = @stream_socket_accept($server, 5);
        if ($client === false) {
            exit(3);
        }
        stream_set_timeout($client, 2);
        if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
            $stats['tls_rejected']++;
            fclose($client);

            continue;
        }
        $requestLine = (string) fgets($client, 4096);
        $length = 0;
        $headerBytes = strlen($requestLine);
        while (($line = fgets($client, 4096)) !== false && trim($line) !== '') {
            $headerBytes += strlen($line);
            if ($headerBytes > 16384) {
                exit(4);
            }
            if (preg_match('/^Content-Length:\s*([0-9]+)\s*$/i', trim($line), $match)) {
                $length = (int) $match[1];
            }
        }
        if ($length < 1 || $length > 4096) {
            exit(5);
        }
        $input = '';
        while (strlen($input) < $length) {
            $chunk = fread($client, $length - strlen($input));
            if ($chunk === false || $chunk === '') {
                exit(6);
            }
            $input .= $chunk;
        }
        $payload = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
        $path = explode(' ', $requestLine)[1] ?? '';
        $stats['paths'][] = $path;
        $port = substr($address, strrpos($address, ':') + 1);
        if ($path === '/redirect') {
            fwrite($client, "HTTP/1.0 302 Found\r\nLocation: https://127.0.0.1:".$port."/unexpected\r\nConnection: close\r\n\r\n");
        } else {
            $body = json_encode(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => '0x1',
                'padding' => $path === '/large' ? str_repeat('x', 16384) : 'small'], JSON_THROW_ON_ERROR);
            // Omit Content-Length so the large case exercises real bounded reads.
            fwrite($client, "HTTP/1.0 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n");
            for ($position = 0; $position < strlen($body); $position += 512) {
                if (@fwrite($client, substr($body, $position, 512)) === false) {
                    break;
                }
            }
        }
        fclose($client);
    }
    fclose($server);
    file_put_contents($directory.'/server-stats.tmp', json_encode($stats, JSON_THROW_ON_ERROR));
    rename($directory.'/server-stats.tmp', $directory.'/server-stats.json');
}

if (($argv[1] ?? '') === '--server') {
    serveAuditTls($argv[2]);
    exit;
}

$directory = null;
$process = null;
$pipes = [];
$stage = 'initialization';
$exitCode = 1;
umask(0077);

try {
    if (PHP_SAPI !== 'cli' || PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5
        || ! extension_loaded('openssl') || ! filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
        throw new RuntimeException('PHP 8.5 CLI with OpenSSL and URL streaming is required.');
    }
    $project = realpath(dirname(__DIR__, 2));
    if ($project === false || ! is_file($project.'/vendor/autoload.php')) {
        throw new RuntimeException('Installed source dependencies are required.');
    }
    require $project.'/vendor/autoload.php';
    $directory = sys_get_temp_dir().'/coin-bot-tls-probe-'.bin2hex(random_bytes(8));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create private temporary storage.');
    }
    $stage = 'ephemeral certificate generation';
    $config = $directory.'/openssl.cnf';
    file_put_contents($config, "[req]\ndistinguished_name=dn\nprompt=no\nreq_extensions=extensions\n"
        ."[dn]\nCN=localhost\n[extensions]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\n"
        ."basicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n"
        ."extendedKeyUsage=serverAuth\n");
    foreach (['trusted', 'untrusted'] as $name) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key,
            ['config' => $config, 'digest_alg' => 'sha256', 'req_extensions' => 'extensions']);
        $certificate = openssl_csr_sign($csr, null, $key, 1,
            ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'extensions']);
        if ($key === false || $csr === false || $certificate === false
            || ! openssl_pkey_export_to_file($key, $directory.'/'.$name.'.key')
            || ! openssl_x509_export_to_file($certificate, $directory.'/'.$name.'.crt')) {
            throw new RuntimeException('Cannot create the ephemeral TLS fixture.');
        }
    }
    unset($key, $csr, $certificate);

    $stage = 'local TLS listener';
    $process = proc_open([PHP_BINARY, __FILE__, '--server', $directory], [
        0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'],
        2 => ['file', $directory.'/server.stderr', 'w'],
    ], $pipes, $project, ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start the controlled TLS listener.');
    }
    stream_set_timeout($pipes[1], 3);
    $port = trim((string) fgets($pipes[1], 32));
    if (! ctype_digit($port)) {
        throw new RuntimeException('The controlled TLS listener did not report its port.');
    }

    // A standalone Factory avoids loading any application environment or services.
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::allowStrayRequests(['https://127.0.0.1:'.$port.'/*']);
    Http::globalOptions(['proxy' => '']);
    $transport = static fn (string $path, string $trust = 'trusted'): RpcTransport => new RpcTransport([
        'rpc_url' => 'https://127.0.0.1:'.$port.$path, 'ca_bundle' => $directory.'/'.$trust.'.crt',
        'attempts' => 1, 'connect_timeout' => 2, 'timeout' => 3, 'max_response_bytes' => 4096,
    ]);

    $stage = 'trusted HTTPS success';
    if ($transport('/small')->rpc('eth_chainId', []) !== '0x1') {
        throw new RuntimeException('Trusted small JSON-RPC response was not accepted.');
    }
    $stage = 'oversized HTTPS body without Content-Length';
    try {
        $transport('/large')->rpc('eth_chainId', []);
        throw new RuntimeException('Oversized TLS body was accepted.');
    } catch (RangeLimitException) {
        // Expected recovery signal; never certify this response as complete.
    }
    $stage = 'TLS trust failure';
    $trustRejected = false;
    try {
        $transport('/private-synthetic-marker', 'untrusted')->rpc('eth_chainId', []);
    } catch (RpcException $exception) {
        $trustRejected = $exception->retryable && $exception->getPrevious() === null
            && ! str_contains((string) $exception, 'private-synthetic-marker')
            && ! str_contains($exception->getMessage(), $directory);
    }
    if (! $trustRejected) {
        throw new RuntimeException('TLS trust failure was not sanitized and rejected.');
    }
    $stage = 'HTTPS redirect rejection';
    $redirectRejected = false;
    try {
        $transport('/redirect')->rpc('eth_chainId', []);
    } catch (RpcException $exception) {
        $redirectRejected = $exception->httpStatus === 302 && ! $exception->retryable;
    }
    if (! $redirectRejected) {
        throw new RuntimeException('HTTPS redirect was followed or misclassified.');
    }
    $stage = 'listener evidence';
    $deadline = hrtime(true) + 3_000_000_000;
    while (! is_file($directory.'/server-stats.json') && hrtime(true) < $deadline) {
        usleep(10000);
    }
    $stats = json_decode((string) file_get_contents($directory.'/server-stats.json'), true, 16, JSON_THROW_ON_ERROR);
    if ($stats !== ['paths' => ['/small', '/large', '/redirect'], 'tls_rejected' => 1]) {
        throw new RuntimeException('Unexpected local TLS requests or handshake evidence.');
    }
    echo "Real RpcTransport TLS probe passed: trusted SAN certificate; small JSON success; oversized headerless body rejected; untrusted certificate sanitized; redirect not followed. No external network or application credentials used.\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    // Never print certificate/key material, child logs, upstream text, or traces.
    fwrite(STDERR, 'Streaming TLS probe failed during '.$stage.' ('.get_class($exception).").\n");
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($directory !== null && is_dir($directory)) {
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}

exit($exitCode);
