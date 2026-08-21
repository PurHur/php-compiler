<?php
/**
 * #23456 — set_exception_handler Reflection + named callback: match Zend stubs.
 *
 * php-src: ext/standard/basic_functions.stub.php
 * AOT accepts compile-time string function names (#4311); closures stay deferred.
 */
$r = new ReflectionFunction('set_exception_handler');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";

function issue_23456_seh_cb($e): void
{
}

set_exception_handler(callback: 'issue_23456_seh_cb');
echo "ok\n";
restore_exception_handler();

try {
    set_exception_handler(exception_handler: 'issue_23456_seh_cb');
    echo "exception_handler accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
