<?php

putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';
$_SERVER['PHP_COMPILER_PROFILE'] = '8.4';

// Zend 8.4 (no strict_types): null soft-coerces (E_DEPRECATED) then ValueError (#28136).
try {
    php_uname(null);
    fwrite(STDERR, "fail: expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    $expected = 'php_uname(): Argument #1 ($mode) must be a single character';
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
