--TEST--
stdlib fpow function_exists on PHP_COMPILER_PROFILE=8.4 — phantoms withheld (#28565, #16677)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$phantoms = array_filter(
    ['fmin', 'fmax', 'fadd', 'fsub', 'fmul', 'nextafter'],
    static fn (string $fn): bool => function_exists($fn)
);
echo [] === $phantoms ? "phantoms-ok\n" : 'phantoms-fail: '.implode(',', $phantoms)."\n";
echo function_exists('fpow') ? "fpow-ok\n" : "fpow-fail\n";
echo 'fpow=', (int) is_float(fpow(2.0, 3.0)), "\n";
--EXPECT--
phantoms-ok
fpow-ok
fpow=1
