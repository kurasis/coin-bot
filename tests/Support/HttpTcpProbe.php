<?php

declare(strict_types=1);

/**
 * Production HTTP smoke with the real bootstrap, HTTP kernel, middleware and routes.
 * Run in a disposable PHP 8.5 container with --network none and a read-only checkout.
 * The temporary front controller bypasses the checkout's .env, maintenance state,
 * caches and storage; no application credentials or databases are inherited.
 */
$directory = null;
$process = null;
$stage = 'initialization';
$exitCode = 1;

try {
    if (PHP_SAPI !== 'cli' || PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5) {
        throw new RuntimeException('PHP 8.5 CLI is required.');
    }
    $project = realpath(dirname(__DIR__, 2));
    if ($project === false || ! is_file($project.'/vendor/autoload.php')
        || ! is_file($project.'/bootstrap/app.php')) {
        throw new RuntimeException('An installed source checkout is required.');
    }

    $directory = sys_get_temp_dir().'/coin-bot-http-tcp-'.bin2hex(random_bytes(8));
    foreach (['', '/storage', '/storage/framework', '/storage/framework/sessions', '/storage/framework/views'] as $suffix) {
        if (! mkdir($directory.$suffix, 0700)) {
            throw new RuntimeException('Cannot create the isolated probe directories.');
        }
    }

    // Match public/index.php's actual kernel entry point, using an empty environment
    // directory and temporary storage before Laravel boot can load local credentials.
    $router = $directory.'/router.php';
    $frontController = '<?php'."\n"
        .'define("LARAVEL_START", microtime(true));'."\n"
        .'require '.var_export($project.'/vendor/autoload.php', true).';'."\n"
        .'$app = require '.var_export($project.'/bootstrap/app.php', true).';'."\n"
        .'$app->useEnvironmentPath('.var_export($directory, true).');'."\n"
        .'$app->useStoragePath('.var_export($directory.'/storage', true).');'."\n"
        .'$app->handleRequest(Illuminate\\Http\\Request::capture());'."\n";
    if (file_put_contents($router, $frontController) !== strlen($frontController)) {
        throw new RuntimeException('Cannot create the isolated HTTP entry point.');
    }

    // proc_open receives this explicit allowlist, never the calling process's env.
    $environment = [
        'PATH' => '/usr/local/bin:/usr/bin:/bin',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_CONFIG_CACHE' => $directory.'/config.php',
        'APP_SERVICES_CACHE' => $directory.'/services.php',
        'APP_PACKAGES_CACHE' => $directory.'/packages.php',
        'APP_ROUTES_CACHE' => $directory.'/routes.php',
        'APP_EVENTS_CACHE' => $directory.'/events.php',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
        'LOG_CHANNEL' => 'null',
        'SESSION_DRIVER' => 'file',
        'CACHE_STORE' => 'array',
        'VIEW_COMPILED_PATH' => $directory.'/storage/framework/views',
    ];

    $stage = 'loopback listener setup';
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
    if ($probe === false) {
        throw new RuntimeException('Cannot reserve a local test port.');
    }
    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    if ($address === false) {
        throw new RuntimeException('Cannot determine the local test port.');
    }
    $process = proc_open([PHP_BINARY, '-S', $address, '-t', $project.'/public', $router], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $directory.'/server.log', 'w'],
        2 => ['file', $directory.'/server.log', 'a'],
    ], $pipes, $project, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start the isolated PHP listener.');
    }

    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @stream_socket_client('tcp://'.$address, $errorNumber, $errorString, 0.05);
        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(10000);
    }
    if (! $ready) {
        throw new RuntimeException('Local PHP listener did not become ready.');
    }

    $checks = 0;
    foreach ([
        ['GET', '/health', '', 200, '{"status":"ok"}'],
        ['HEAD', '/health', '', 200, ''],
        ['GET', '/up', '', 200, '{"status":"up"}'],
        ['HEAD', '/up', '', 200, ''],
        ['GET', '/health?user_id=1&user_id=2&tenant_id=2&limit=-1', '', 200, '{"status":"ok"}'],
        ['GET', '/health', '{invalid-json', 200, '{"status":"ok"}'],
        ['GET', '/api/v1/users/2', '', 404, null],
        ['POST', '/api/wallets', '{"user_id":2,"role":"admin"}', 404, null],
        ['POST', '/webhooks/telegram', '{"update_id":1}', 404, null],
        ['POST', '/graphql', '{"query":"{ users { id } }"}', 404, null],
        ['GET', '/storage/private.txt?signature=invalid', '', 404, null],
        ['PUT', '/storage/private.txt?upload=1&signature=invalid', 'bounded-upload-marker', 404, null],
        ['GET', '/.env', '', 404, null],
        ['GET', '/composer.lock', '', 404, null],
        ['POST', '/health', '{"role":"admin"}', 405, null],
        ['DELETE', '/up', '', 405, null],
    ] as [$method, $path, $body, $expectedStatus, $expectedBody]) {
        $stage = 'HTTP case '.($checks + 1);
        $socket = stream_socket_client('tcp://'.$address, $errorNumber, $errorString, 2);
        if ($socket === false) {
            throw new RuntimeException('Cannot connect to the local listener.');
        }
        stream_set_timeout($socket, 3);
        $request = $method.' '.$path." HTTP/1.1\r\nHost: coin-bot-audit.invalid\r\nAccept: application/json\r\n"
            ."Content-Type: application/json\r\nContent-Length: ".strlen($body)."\r\n"
            ."X-Forwarded-For: 149.154.160.1\r\nX-User-ID: 2\r\nX-Tenant-ID: 2\r\nConnection: close\r\n\r\n".$body;
        if (fwrite($socket, $request) !== strlen($request)) {
            fclose($socket);
            throw new RuntimeException('Incomplete local HTTP request write.');
        }
        $raw = stream_get_contents($socket, 65536);
        $metadata = stream_get_meta_data($socket);
        fclose($socket);
        if ($raw === false || $metadata['timed_out'] || ! str_contains($raw, "\r\n\r\n")) {
            throw new RuntimeException('Incomplete local HTTP response.');
        }
        [$headers, $responseBody] = explode("\r\n\r\n", $raw, 2);
        preg_match('/^HTTP\/1\.[01] ([0-9]{3})/', $headers, $match);
        $status = (int) ($match[1] ?? 0);
        if ($status !== $expectedStatus || ($expectedBody !== null && $responseBody !== $expectedBody)) {
            throw new RuntimeException('Unexpected local HTTP result.');
        }
        if (stripos($headers, 'Set-Cookie:') !== false) {
            throw new RuntimeException('Unexpected cookie on a stateless or absent endpoint.');
        }
        $checks++;
        printf("%s %s: %d; no cookies\n", $method, parse_url($path, PHP_URL_PATH), $status);
    }
    $stage = 'file-session inspection';
    if (glob($directory.'/storage/framework/sessions/*') !== []) {
        throw new RuntimeException('A public request unexpectedly created a file session.');
    }
    echo "Production loopback TCP audit passed: $checks bounded HTTP requests; temporary configuration; no cookies or file sessions.\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    // Do not print response bodies, child logs, environment values, or trace data.
    fwrite(STDERR, 'HTTP TCP probe failed during '.$stage.' ('.get_class($exception).").\n");
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($directory !== null && is_dir($directory)) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}

exit($exitCode);
