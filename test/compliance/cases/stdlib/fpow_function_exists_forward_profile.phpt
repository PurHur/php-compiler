--TEST--
stdlib fpow/fmin/fmax/fadd/fsub/fmul/nextafter function_exists on PHP_COMPILER_PROFILE=8.4 (#16677, #17290)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$missing = array_filter(
    ['fpow', 'fmin', 'fmax', 'fadd', 'fsub', 'fmul', 'nextafter'],
    static fn (string $fn): bool => !function_exists($fn)
);
echo [] === $missing ? "ok\n" : 'fail: '.implode(',', $missing)."\n";
echo 'fpow=', (int) is_float(fpow(2.0, 3.0)), "\n";
echo 'fadd=', (int) is_float(fadd(0.1, 0.2)), "\n";
--EXPECT--
ok
fpow=1
fadd=1
