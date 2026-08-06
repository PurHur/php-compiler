<?php
/** Issue #28063 — lzf_optimized_for() when lzf advertised (PECL lzf.stub.php). */
echo 'ext=', extension_loaded('lzf') ? '1' : '0', PHP_EOL;
foreach (['lzf_compress', 'lzf_decompress', 'lzf_optimized_for'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', PHP_EOL;
}
if (function_exists('lzf_optimized_for')) {
    $v = lzf_optimized_for();
    echo 'val=', var_export($v, true), PHP_EOL;
    echo 'is_int=', is_int($v) ? '1' : '0', PHP_EOL;
    echo 'speed=', (1 === $v) ? '1' : '0', PHP_EOL;
    $r = new ReflectionFunction('lzf_optimized_for');
    echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', PHP_EOL;
    echo 'argc=', $r->getNumberOfParameters(), PHP_EOL;
}
