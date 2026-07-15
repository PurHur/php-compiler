<?php

declare(strict_types=1);

putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';
$_SERVER['PHP_COMPILER_PROFILE'] = '8.4';

try {
    php_uname(null);
    fwrite(STDERR, "fail: expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    $expected = 'php_uname(): Argument #1 ($mode) must be of type string, null given';
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, 'fail: got '.$e->getMessage()."\n");
        exit(1);
    }
    echo "ok\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'fail: got '.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
