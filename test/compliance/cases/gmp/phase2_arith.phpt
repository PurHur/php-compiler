--TEST--
gmp phase-2 pow/mod/div/abs/neg/bitwise/intval (ext/gmp/gmp.c; issue #19527)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['gmp_pow','gmp_mod','gmp_div_q','gmp_div_r','gmp_div_qr','gmp_abs','gmp_neg','gmp_and','gmp_or','gmp_xor','gmp_intval'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}
echo gmp_strval(gmp_pow(2, 10)), "\n";
echo gmp_strval(gmp_mod(10, 3)), "\n";
echo gmp_strval(gmp_mod(-10, 3)), "\n";
echo gmp_strval(gmp_div_q(-10, 3)), "\n";
echo gmp_strval(gmp_div_r(-10, 3)), "\n";
echo gmp_strval(gmp_abs(-5)), "\n";
echo gmp_strval(gmp_neg(5)), "\n";
echo gmp_strval(gmp_and(12, 10)), "\n";
echo gmp_strval(gmp_or(12, 10)), "\n";
echo gmp_strval(gmp_xor(12, 10)), "\n";
echo gmp_strval(gmp_and(-5, 3)), "\n";
echo gmp_strval(gmp_or(-5, 3)), "\n";
echo gmp_strval(gmp_xor(-5, 3)), "\n";
echo gmp_intval(gmp_init('42')), "\n";
$qr = gmp_div_qr(17, 5);
echo gmp_strval($qr[0]), ' ', gmp_strval($qr[1]), "\n";
?>
--EXPECT--
gmp_pow=yes
gmp_mod=yes
gmp_div_q=yes
gmp_div_r=yes
gmp_div_qr=yes
gmp_abs=yes
gmp_neg=yes
gmp_and=yes
gmp_or=yes
gmp_xor=yes
gmp_intval=yes
1024
1
2
-3
-1
5
-5
8
14
6
3
-5
-8
42
3 2
