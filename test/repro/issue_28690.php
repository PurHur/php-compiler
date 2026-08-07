<?php
/**
 * getenv/putenv/ini helpers/error_reporting/trigger_error excess argc → ArgumentCountError (#28690).
 * php-src: ext/standard/basic_functions.c / basic_functions.stub.php
 */
error_reporting(E_ALL);
$cases = [
    'getenv' => static fn () => getenv('PATH', true, 'x'),
    'putenv' => static fn () => putenv('A=1', 'x'),
    'ini_get' => static fn () => ini_get('memory_limit', 'x'),
    'ini_set' => static fn () => ini_set('display_errors', '1', 'x'),
    'error_reporting' => static fn () => error_reporting(E_ALL, 'x'),
    'trigger_error' => static fn () => trigger_error('x', E_USER_NOTICE, 'y'),
    'trigger_error_zero' => static fn () => trigger_error(),
];
foreach ($cases as $k => $f) {
    try {
        $f();
        echo "$k:OK\n";
    } catch (Throwable $e) {
        echo $k, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
