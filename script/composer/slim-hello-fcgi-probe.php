#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Minimal FastCGI GET client for Slim hello Done-when (#36382).
 *
 * Speaks the same framing as php-src sapi/fpm (draft-fcgi-spec) via
 * PHPCompiler\Web\FastCgi\{Request,Record} so CI does not need cgi-fcgi.
 *
 * Usage:
 *   php script/composer/slim-hello-fcgi-probe.php --connect 127.0.0.1:19082 \
 *     --docroot test/fixtures/aot/projects/slim_hello_36382/public \
 *     --uri /hello
 *
 * Exit 0 prints FCGI_STDOUT (CGI Status/headers/body). Non-zero on connect/protocol failure.
 */

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

use PHPCompiler\Web\FastCgi\Record;
use PHPCompiler\Web\FastCgi\Request;

$connect = '127.0.0.1:19082';
$uri = '/hello';
$scriptName = '/index.php';
$docroot = $root.'/test/fixtures/aot/projects/slim_hello_36382/public';
$timeout = 5.0;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ('--connect' === $arg) {
        $connect = (string) ($argv[++$i] ?? '');
    } elseif (str_starts_with($arg, '--connect=')) {
        $connect = substr($arg, strlen('--connect='));
    } elseif ('--uri' === $arg) {
        $uri = (string) ($argv[++$i] ?? '');
    } elseif (str_starts_with($arg, '--uri=')) {
        $uri = substr($arg, strlen('--uri='));
    } elseif ('--script' === $arg) {
        $scriptName = (string) ($argv[++$i] ?? '');
    } elseif (str_starts_with($arg, '--script=')) {
        $scriptName = substr($arg, strlen('--script='));
    } elseif ('--docroot' === $arg) {
        $docroot = (string) ($argv[++$i] ?? '');
    } elseif (str_starts_with($arg, '--docroot=')) {
        $docroot = substr($arg, strlen('--docroot='));
    } elseif ('--timeout' === $arg) {
        $timeout = (float) ($argv[++$i] ?? 5);
    } elseif (str_starts_with($arg, '--timeout=')) {
        $timeout = (float) substr($arg, strlen('--timeout='));
    } elseif (in_array($arg, ['-h', '--help'], true)) {
        fwrite(STDOUT, "Usage: slim-hello-fcgi-probe.php --connect host:port [--uri /hello] [--docroot PATH]\n");
        exit(0);
    } else {
        fwrite(STDERR, "slim-hello-fcgi-probe: unknown arg {$arg}\n");
        exit(2);
    }
}

if ('' === $connect || !str_contains($connect, ':')) {
    fwrite(STDERR, "slim-hello-fcgi-probe: --connect requires host:port\n");
    exit(2);
}
if (!str_starts_with($docroot, '/')) {
    $docroot = $root.'/'.ltrim($docroot, '/');
}
$docrootReal = realpath($docroot);
if (false === $docrootReal || !is_dir($docrootReal)) {
    fwrite(STDERR, "slim-hello-fcgi-probe: missing docroot {$docroot}\n");
    exit(2);
}
$scriptFile = $docrootReal.'/'.ltrim($scriptName, '/');
if (!is_file($scriptFile)) {
    fwrite(STDERR, "slim-hello-fcgi-probe: missing SCRIPT_FILENAME {$scriptFile}\n");
    exit(2);
}

$params = [
    'REQUEST_METHOD' => 'GET',
    'SCRIPT_FILENAME' => $scriptFile,
    'SCRIPT_NAME' => $scriptName,
    'REQUEST_URI' => $uri,
    'PATH_INFO' => $uri,
    'DOCUMENT_ROOT' => $docrootReal,
    'QUERY_STRING' => '',
    'CONTENT_LENGTH' => '0',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'HTTP_HOST' => '127.0.0.1',
    'GATEWAY_INTERFACE' => 'CGI/1.1',
];

$errno = 0;
$errstr = '';
$client = @stream_socket_client('tcp://'.$connect, $errno, $errstr, $timeout);
if (false === $client) {
    fwrite(STDERR, "slim-hello-fcgi-probe: connect tcp://{$connect} failed: {$errstr}\n");
    exit(1);
}
stream_set_timeout($client, (int) ceil($timeout));

fwrite($client, Request::encode(1, $params, '', Record::ROLE_RESPONDER, 0));

$stdoutBody = '';
$endSeen = false;
while (!$endSeen) {
    $record = Record::readFromStream($client);
    if (null === $record) {
        break;
    }
    if (Record::STDOUT === $record['type']) {
        $stdoutBody .= $record['content'];
    }
    if (Record::END_REQUEST === $record['type']) {
        $endSeen = true;
    }
}
fclose($client);

if (!$endSeen) {
    fwrite(STDERR, "slim-hello-fcgi-probe: no FCGI_END_REQUEST (got ".strlen($stdoutBody)." stdout bytes)\n");
    exit(1);
}

echo $stdoutBody;
exit(0);
