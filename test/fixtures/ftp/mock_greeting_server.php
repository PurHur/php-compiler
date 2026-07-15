<?php
/**
 * Minimal FTP greeting server for #6762 TypeError probes (QUIT → exit).
 * Usage: php test/fixtures/ftp/mock_greeting_server.php <port> [ready-file]
 */
declare(strict_types=1);

$port = isset($argv[1]) ? (int) $argv[1] : 0;
$readyFile = $argv[2] ?? '';
if ($port <= 0) {
    fwrite(STDERR, "usage: mock_greeting_server.php <port> [ready-file]\n");
    exit(1);
}

$server = @stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $errstr);
if (false === $server) {
    fwrite(STDERR, "bind fail: $errstr\n");
    exit(1);
}
if ('' !== $readyFile) {
    file_put_contents($readyFile, 'ready');
}
fwrite(STDOUT, "ready\n");
fflush(STDOUT);

$client = @stream_socket_accept($server, 8.0);
if (false === $client) {
    exit(1);
}
fwrite($client, "220 mock ready\r\n");
while (!feof($client)) {
    $line = fgets($client, 512);
    if (false === $line) {
        break;
    }
    $cmd = strtoupper(strtok(trim($line), ' ') ?: '');
    if ('QUIT' === $cmd) {
        fwrite($client, "221 bye\r\n");
        break;
    }
    if ('USER' === $cmd) {
        fwrite($client, "331 password required\r\n");
        continue;
    }
    if ('PASS' === $cmd) {
        fwrite($client, "230 logged in\r\n");
        continue;
    }
    fwrite($client, "502 not implemented\r\n");
}
fclose($client);
fclose($server);
