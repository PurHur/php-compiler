<?php
/**
 * SOAP HTTP echo server that emits Set-Cookie (php-src php_http.c cookie ingest).
 * Usage: php mock_http_setcookie_server.php <port> <ready-file> <body-xml> [max-requests=4]
 */
declare(strict_types=1);

$port = isset($argv[1]) ? (int) $argv[1] : 0;
$readyFile = $argv[2] ?? '';
$bodyPath = $argv[3] ?? '';
$maxRequests = isset($argv[4]) ? (int) $argv[4] : 4;
if ($port <= 0 || '' === $bodyPath) {
    fwrite(STDERR, "usage: mock_http_setcookie_server.php <port> <ready-file> <body-xml> [max-requests]\n");
    exit(1);
}

$body = file_get_contents($bodyPath);
if (false === $body) {
    fwrite(STDERR, "missing body xml\n");
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
    $headers = '';
    while (!str_contains($headers, "\r\n\r\n")) {
        $chunk = fread($client, 1024);
        if (false === $chunk || '' === $chunk) {
            break;
        }
        $headers .= $chunk;
        if (strlen($headers) > 65536) {
            break;
        }
    }
    $sep = strpos($headers, "\r\n\r\n");
    if (false === $sep) {
        fclose($client);
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

    $response = "HTTP/1.1 200 OK\r\n".
        "Content-Type: text/xml; charset=utf-8\r\n".
        'Content-Length: '.strlen($body)."\r\n".
        "Set-Cookie: sess=abc123; path=/echo; domain=127.0.0.1\r\n".
        "Set-Cookie: tok=xyz; secure\r\n".
        "Connection: close\r\n".
        "\r\n".
        $body;
    fwrite($client, $response);
    fclose($client);
    ++$served;
}
fclose($server);
