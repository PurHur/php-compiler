<?php
/**
 * Minimal keep-alive SOAP HTTP echo server for #24913.
 * Usage: php test/fixtures/soap/mock_http_echo_server.php <port> [ready-file] [max-requests=4]
 */
declare(strict_types=1);

$port = isset($argv[1]) ? (int) $argv[1] : 0;
$readyFile = $argv[2] ?? '';
$maxRequests = isset($argv[3]) ? (int) $argv[3] : 4;
if ($port <= 0) {
    fwrite(STDERR, "usage: mock_http_echo_server.php <port> [ready-file] [max-requests]\n");
    exit(1);
}

$body = file_get_contents(__DIR__.'/echo.response.xml');
if (false === $body) {
    fwrite(STDERR, "missing echo.response.xml\n");
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

$served = 0;
while ($served < $maxRequests) {
    $client = @stream_socket_accept($server, 8.0);
    if (false === $client) {
        break;
    }
    stream_set_timeout($client, 5);
    while ($served < $maxRequests) {
        $headers = '';
        while (!str_contains($headers, "\r\n\r\n")) {
            $chunk = fread($client, 1024);
            if (false === $chunk || '' === $chunk) {
                break 2;
            }
            $headers .= $chunk;
            if (strlen($headers) > 65536) {
                break 2;
            }
        }
        $sep = strpos($headers, "\r\n\r\n");
        if (false === $sep) {
            break;
        }
        $headerBlock = substr($headers, 0, $sep);
        $rest = substr($headers, $sep + 4);
        $contentLength = 0;
        if (preg_match('/^Content-Length:\s*(\d+)/mi', $headerBlock, $m)) {
            $contentLength = (int) $m[1];
        }
        while (strlen($rest) < $contentLength) {
            $chunk = fread($client, $contentLength - strlen($rest));
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $rest .= $chunk;
        }

        $clientWantsClose = (bool) preg_match('/^Connection:\s*close\s*$/mi', $headerBlock);
        $connection = $clientWantsClose ? 'close' : 'Keep-Alive';
        $response = "HTTP/1.1 200 OK\r\n".
            "Content-Type: text/xml; charset=utf-8\r\n".
            'Content-Length: '.strlen($body)."\r\n".
            'Connection: '.$connection."\r\n".
            "\r\n".
            $body;
        fwrite($client, $response);
        ++$served;
        if ($clientWantsClose) {
            break;
        }
    }
    fclose($client);
}
fclose($server);
