--TEST--
Language: public private(set) rejected on default 8.4.0-dev reference profile (#30856, re-#24819 / #30205, Zend/zend_compile.c)
--SKIPIF--
<?php
// Key off PROFILE env — not supportsAsymmetricVisibility(). If the gate wrongly
// returns true on the reference profile, skipping would hide the regression (#30856 / #24819).
$raw = getenv('PHP_COMPILER_PROFILE');
if (is_string($raw) && '' !== trim($raw)) {
    $v = trim($raw);
    if (preg_match('/^\d+\.\d+$/', $v)) {
        $v .= '.0';
    }
    if (version_compare($v, '8.4.0', '>=')) {
        die('skip asymmetric visibility enabled on PHP 8.4+ forward profile');
    }
}
?>
--FILE--
<?php
class C {
    public private(set) string $name = 'Alice';
}
echo "parsed\n";
echo (new C())->name, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Multiple access type modifiers are not allowed in %s on line %d
