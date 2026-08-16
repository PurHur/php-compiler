<?php
/**
 * #23341 — error_log Reflection names + Zend-style named args
 * php-src: ext/standard/basic_functions.stub.php
 */
$r = new ReflectionFunction('error_log');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' ';
}
echo "\n";
try {
    var_export(error_log(message: 'parity', message_type: 0));
    echo "\n";
} catch (Throwable $e) {
    echo 'named message ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(error_log(message: 'parity', message_type: 0, additional_headers: 'X-Test: 1'));
    echo "\n";
} catch (Throwable $e) {
    echo 'named additional_headers ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    error_log(message: 'parity', message_type: 0, extra_headers: 'X-Test: 1');
    echo "legacy extra_headers ok\n";
} catch (Throwable $e) {
    echo 'legacy extra_headers ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
