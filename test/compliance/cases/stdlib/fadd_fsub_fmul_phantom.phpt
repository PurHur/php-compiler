--TEST--
stdlib fadd()/fsub()/fmul() phantoms on PROFILE≥8.4 — absent from php-src (#28565)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['fadd', 'fsub', 'fmul'] as $fn) {
    echo $fn, '=', function_exists($fn) ? '1' : '0', "\n";
}
echo function_exists('fpow') ? "fpow-ok\n" : "fpow-fail\n";
?>
--EXPECT--
fadd=0
fsub=0
fmul=0
fpow-ok
