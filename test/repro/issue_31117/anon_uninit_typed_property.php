<?php
// #31117 — uninit typed property Error on anonymous class must use Zend display name (no NUL/path).
$c = new class { public int $x; };
try {
    echo $c->x;
    echo "UNEXPECTED_OK\n";
} catch (Throwable $e) {
    $msg = $e->getMessage();
    echo "msg=" . $msg . "\n";
    echo "has_nul=" . (strpos($msg, "\0") !== false ? "yes" : "no") . "\n";
    echo "exact=" . ($msg === 'Typed property class@anonymous::$x must not be accessed before initialization' ? "yes" : "no") . "\n";
}
$className = get_class($c);
echo "get_class_has_nul=" . (strpos($className, "\0") !== false ? "yes" : "no") . "\n";
