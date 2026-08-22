<?php
/**
 * #33936 — AOT: dim fetch on static array property after keyed INIT_ARRAY.
 * Must not free FETCH_STATIC_PROP_R temps across the string-key CFG JUMP (#23354),
 * and must not needlessly split when the dim container is that Temporary (#33944).
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
echo C::$a['x'], "\n";
$v = C::$a['x'];
echo $v, "\n";
echo count(C::$a), ':', C::$a['x'], "\n";
$t = C::$a;
echo $t['x'], "\n";
echo C::$a['x'] ?? 'missing', "\n";
C::$a = [0 => 9, 'x' => 1];
echo C::$a[0], ':', C::$a['x'], "\n";
