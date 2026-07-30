--TEST--
Language: final plain property rejected on default 8.4.0-dev reference profile (#25379, re-#24895/#24822/#24316, Zend/zend_compile.c)
--SKIPIF--
<?php
// Key off PROFILE env — not supportsFinalProperties(). If the gate wrongly
// returns true on the reference profile, skipping would hide the regression (#24895).
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
    public final int $x = 1;
}
echo "parsed\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot declare property C::$x final, the final modifier is allowed only for methods, classes, and class constants in %s on line %d
