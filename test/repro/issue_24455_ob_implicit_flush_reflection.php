<?php
/**
 * Issue #24455 — ob_implicit_flush Reflection/named args use enable (Zend stub), not flag.
 *
 * php-src: ext/standard/basic_functions.stub.php
 *   function ob_implicit_flush(bool $enable = true): void
 */
$names = [];
foreach ((new ReflectionFunction('ob_implicit_flush'))->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";

ob_implicit_flush(enable: 1);
echo "enable_ok\n";

try {
    ob_implicit_flush(flag: 1);
    echo "flag_accepted\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
