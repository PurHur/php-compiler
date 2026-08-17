<?php
// Typed by-ref parameters must TypeError / in-place-coerce like Zend ZEND_RECV (not skip checks).
error_reporting(E_ALL);
function t(int &$x) { $x++; echo 'in=', var_export($x, true), "\n"; }

echo "== int ==\n";
$a = 1;
t($a);
echo 'after=', var_export($a, true), "\n";

echo "== numeric_string ==\n";
$b = '1';
try {
    t($b);
    echo 'after=', var_export($b, true), ' ', gettype($b), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo "== non_numeric_string ==\n";
$c = 's';
try {
    t($c);
    echo 'after=', var_export($c, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo "== float ==\n";
$d = 1.5;
try {
    t($d);
    echo 'after=', var_export($d, true), ' ', gettype($d), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo "== null ==\n";
$e = null;
try {
    t($e);
    echo 'after=', var_export($e, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo "== bool ==\n";
$f = true;
try {
    t($f);
    echo 'after=', var_export($f, true), ' ', gettype($f), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
