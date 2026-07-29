--TEST--
Language: cast in class constant initializer must compile-error (#24905, Zend/zend_compile.c)
--SKIPIF--
<?php
// PROFILE≥8.5 allows scalar casts in const exprs (#24947); keep ≤8.4 reject coverage here.
$raw = getenv('PHP_COMPILER_PROFILE');
if (is_string($raw) && '' !== trim($raw)) {
    $v = trim($raw);
    if (preg_match('/^\d+\.\d+$/', $v)) {
        $v .= '.0';
    }
    if (version_compare($v, '8.5.0', '>=')) {
        die('skip cast reject is ≤8.4 only; see cast_const_expr_85.phpt');
    }
}
?>
--FILE--
<?php
class A {
    public const X = (int) "5";
}
echo A::X, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Constant expression contains invalid operations
