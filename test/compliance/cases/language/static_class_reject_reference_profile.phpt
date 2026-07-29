--TEST--
Language: static class rejected on default/8.2 reference profile (#24894, re-#6929, Zend/zend_language_parser.y)
--SKIPIF--
<?php
// Key off PROFILE env — not supportsStaticClass(). If the gate wrongly
// returns true on the reference profile, skipping would hide the regression (#24894).
$raw = getenv('PHP_COMPILER_PROFILE');
if (is_string($raw) && '' !== trim($raw)) {
    $v = trim($raw);
    if (preg_match('/^\d+\.\d+$/', $v)) {
        $v .= '.0';
    }
    if (version_compare($v, '8.4.0', '>=')) {
        die('skip static class enabled on PHP 8.4+ forward profile');
    }
}
?>
--FILE--
<?php
static class A { public static function f(){ return 1; } }
echo A::f(), "\n";
--EXPECT_EXIT--
255
--EXPECTF--
Parse error: syntax error, unexpected token "class", expecting "::" in %s on line %d
