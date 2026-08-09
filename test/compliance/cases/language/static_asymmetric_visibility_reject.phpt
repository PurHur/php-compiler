--TEST--
PHP 8.4 static asymmetric visibility: Zend "Static property may not have asymmetric visibility" (#7013, #29389, zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
// Key off PROFILE env — not supportsStaticAsymmetricVisibility(). PHP 8.5 accepts static aviz (#26239).
$profile = getenv('PHP_COMPILER_PROFILE');
if (is_string($profile) && '' !== trim($profile)
    && version_compare(preg_replace('/^php-/i', '', trim($profile)) ?: '0', '8.5.0', '>=')) {
    die('skip static aviz accepted on PHP 8.5+ forward profile (#26239)');
}
?>
--FILE--
<?php
class C {
    public private(set) static int $x = 1;
}
echo C::$x, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
%aStatic property may not have asymmetric visibility%a
