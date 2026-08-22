<?php
/**
 * #33936 — AOT: dim fetch on static array property after keyed INIT_ARRAY.
 * CFG split before string-key dim must not free the FETCH_STATIC_PROP_R temp.
 *
 *   ./script/aot-smoke.sh
 *   ./script/docker-exec.sh -- bash -lc 'export PHP_COMPILER_HELPER_RUNTIME_O=0; php bin/compile.php -o /tmp/sa33936.bin test/repro/issue_33936_static_array_dim_aot.php && /tmp/sa33936.bin'
 *
 * @see php-src Zend/zend_execute.c zend_fetch_dimension / ZEND_FETCH_STATIC_PROP_R
 */
class C
{
    public static $a;
}
C::$a = ['x' => 1];
echo C::$a['x'];
echo "\n";
echo count(C::$a), ':', C::$a['x'];
echo "\n";
$t = C::$a;
echo $t['x'];
echo "\n";
echo C::$a['x'] ?? 'missing';
echo "\n";
C::$a = [0 => 9, 'x' => 1];
echo C::$a[0], ':', C::$a['x'];
echo "\n";
