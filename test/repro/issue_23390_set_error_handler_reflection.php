<?php
/**
 * #23390 — set_error_handler Reflection + named callback:/error_levels: match Zend stubs.
 *
 * php-src: ext/standard/basic_functions.stub.php
 */
$r = new ReflectionFunction('set_error_handler');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";

function issue_23390_seh_cb(): bool
{
    return false;
}

set_error_handler(callback: 'issue_23390_seh_cb');
echo "ok\n";
set_error_handler(callback: 'issue_23390_seh_cb', error_levels: E_WARNING);
echo "ok_levels\n";

try {
    set_error_handler(error_handler: 'issue_23390_seh_cb');
    echo "error_handler accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

try {
    set_error_handler(callback: 'issue_23390_seh_cb', error_types: E_ALL);
    echo "error_types accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
