<?php
// Repro for #24771: WeakMap offsetGet on missing key must throw Error (zend_weakmap.c)
$wm = new WeakMap();
$o = new stdClass();
try {
    $v = $wm[$o];
    echo 'got=', var_export($v, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', preg_replace('/#\d+/', '#N', $e->getMessage()), "\n";
}
try {
    $v = $wm->offsetGet($o);
    echo 'method_got=', var_export($v, true), "\n";
} catch (Throwable $e) {
    echo 'method ', get_class($e), ': ', preg_replace('/#\d+/', '#N', $e->getMessage()), "\n";
}
$wm[$o] = 1;
echo 'present=', $wm[$o], "\n";
