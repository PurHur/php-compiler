<?php
/** Repro #28334 — nl_langinfo Reflection return is string|false (php-src basic_functions.stub.php). */
$r = new ReflectionFunction('nl_langinfo');
echo $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
if (\defined('CODESET')) {
    $v = nl_langinfo(CODESET);
    echo \is_string($v) ? 'ok' : 'bad', PHP_EOL;
}
