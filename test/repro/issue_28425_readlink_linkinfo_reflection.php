<?php
/**
 * #28425 — readlink / linkinfo Reflection return string|false / int|false
 * (ext/standard/link.stub.php / basic_functions.stub.php).
 */
foreach (['readlink', 'linkinfo'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
}
$missing = @readlink('/no/such/file/28425');
echo 'readlink_missing=', (false === $missing) ? 'false' : gettype($missing), "\n";
$dev = @linkinfo('/no/such/file/28425');
echo 'linkinfo_missing=', (-1 === $dev) ? '-1' : var_export($dev, true), "\n";
