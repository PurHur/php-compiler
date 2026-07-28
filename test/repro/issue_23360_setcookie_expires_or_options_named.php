<?php
// Issue #23360 — setcookie/setrawcookie Zend stub named expires_or_options
// (ext/standard/basic_functions.stub.php / head.c).
$lines = [];
foreach (['setcookie', 'setrawcookie'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    $lines[] = $fn.'_params:'.implode(',', $names);
    try {
        $ok = $fn(name: 'n', value: 'v', expires_or_options: 0);
        $lines[] = $fn.'_named:'.($ok ? '1' : '0');
    } catch (Throwable $e) {
        $lines[] = $fn.'_named_err:'.$e->getMessage();
    }
    try {
        $fn(name: 'n', value: 'v', expires: 0);
        $lines[] = $fn.'_expires_legacy:accepted';
    } catch (Throwable $e) {
        $lines[] = $fn.'_expires_legacy:'.$e->getMessage();
    }
}
echo implode(PHP_EOL, $lines), PHP_EOL;
?>
