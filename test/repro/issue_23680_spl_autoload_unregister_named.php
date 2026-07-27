<?php
// Issue #23680 — spl_autoload_unregister Zend stub named callback param.
$cb = function ($c) {};
spl_autoload_register($cb);
$rf = new ReflectionFunction('spl_autoload_unregister');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params:', implode(',', $names), "\n";
$ok = spl_autoload_unregister(callback: $cb);
echo 'ok:', $ok ? '1' : '0', "\n";
$legacy = null;
try {
    spl_autoload_unregister(autoload_function: $cb);
    $legacy = 'autoload_function accepted';
} catch (Throwable $e) {
    $legacy = $e->getMessage();
}
echo $legacy, "\n";
?>
