--TEST--
Language: final static property rejected on 8.2 reference profile (#23403, re-#22308, Zend/zend_compile.c)
--SKIPIF--
<?php
// Key off PROFILE env — not supportsFinalProperties() — so a broken gate cannot
// skip this case and hide a reference-profile regression (#24316 family).
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
class A {
    public final static $x = 1;
}
echo "parsed_ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot declare property A::$x final, the final modifier is allowed only for methods, classes, and class constants
