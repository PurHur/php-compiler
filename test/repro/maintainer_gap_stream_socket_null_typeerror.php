<?php

declare(strict_types=1);

putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';
$_SERVER['PHP_COMPILER_PROFILE'] = '8.4';

$checks = [
    ['stream_socket_client', static fn () => stream_socket_client(null)],
    ['fsockopen', static fn () => fsockopen(null)],
];

foreach ($checks as [$fn, $call]) {
    try {
        $call();
        fwrite(STDERR, "fail: {$fn}(null) expected TypeError\n");
        exit(1);
    } catch (TypeError $e) {
        $expected = $fn.'(): Argument #1 ($'.($fn === 'fsockopen' ? 'hostname' : 'remote_socket').') must be of type string, null given';
        if ($expected !== $e->getMessage()) {
            fwrite(STDERR, "fail: {$fn}(null) got ".$e->getMessage()."\n");
            exit(1);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "fail: {$fn}(null) got ".get_class($e).': '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
