<?php
// Zend 8.4: php_uname() ValueError on invalid $mode (ext/standard/info.c, #28136).
putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';
$_SERVER['PHP_COMPILER_PROFILE'] = '8.4';

foreach (['z', '', "\0", 'aa', 'a', 's'] as $m) {
    try {
        $r = php_uname($m);
        echo 'mode=', var_export($m, true), ' ACCEPTED len=', strlen((string) $r), "\n";
    } catch (Throwable $e) {
        echo 'mode=', var_export($m, true), ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
