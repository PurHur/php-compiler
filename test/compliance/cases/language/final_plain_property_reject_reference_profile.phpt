--TEST--
Language: final plain property rejected on 8.2 reference profile (#24316, re-#22308/#22241, Zend/zend_compile.c)
--SKIPIF--
<?php
// Key off PROFILE env — not supportsFinalProperties(). If the gate wrongly
// returns true on the reference profile, skipping would hide the regression
// (#24316 "gate did not stick" / re-#24216 family).
$raw = getenv('PHP_COMPILER_PROFILE');
if (is_string($raw) && '' !== trim($raw)) {
    $v = trim($raw);
    if (preg_match('/^\d+\.\d+$/', $v)) {
        $v .= '.0';
    }
    if (version_compare($v, '8.4.0', '>=')) {
        die('skip final properties enabled on PHP 8.4+ forward profile');
    }
}
?>
--FILE--
<?php
class C {
    final public string $x = 'a';
}
$o = new C();
echo "declare=ok value={$o->x}\n";
$o->x = 'b';
echo "write={$o->x}\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot declare property C::$x final, the final modifier is allowed only for methods, classes, and class constants
