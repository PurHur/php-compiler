--TEST--
gmp phase-4 random/import/export (ext/gmp/gmp.c; issue #19540)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['gmp_random_seed','gmp_random_bits','gmp_random_range','gmp_import','gmp_export'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}
echo defined('GMP_MSW_FIRST') ? 'const=yes' : 'const=no', "\n";
gmp_random_seed(42);
$a = gmp_strval(gmp_random_bits(16));
$b = gmp_strval(gmp_random_bits(16));
gmp_random_seed(42);
$c = gmp_strval(gmp_random_bits(16));
echo ($a === $c) ? 'seed_ok' : 'seed_bad', "\n";
echo ($a !== $b) ? 'vary_ok' : 'vary_same', "\n";
$r = gmp_random_range(10, 20);
$rv = gmp_intval($r);
echo ($rv >= 10 && $rv <= 20) ? 'range_ok' : 'range_bad', "\n";
echo gmp_strval(gmp_import("\0\1\2")), "\n";
echo gmp_export(gmp_init(16705)), "\n";
$round = gmp_strval(gmp_import(gmp_export(gmp_init(123456789))));
echo $round, "\n";
?>
--EXPECT--
gmp_random_seed=yes
gmp_random_bits=yes
gmp_random_range=yes
gmp_import=yes
gmp_export=yes
const=yes
seed_ok
vary_ok
range_ok
258
AA
123456789
