--TEST--
Language: `new Class()->method()` rejected on default 8.4.0-dev reference profile (#24883, re-#22783 / #24755, Zend/zend_language_parser.y)
--SKIPIF--
<?php
// Key off PROFILE env — not supportsDereferencableNewWithoutOuterParens(). If the gate
// wrongly returns true on the reference profile, skipping would hide the regression (#24883).
$raw = getenv('PHP_COMPILER_PROFILE');
if (is_string($raw) && '' !== trim($raw)) {
    $v = trim($raw);
    if (preg_match('/^\d+\.\d+$/', $v)) {
        $v .= '.0';
    }
    if (version_compare($v, '8.4.0', '>=')) {
        die('skip dereferencable new enabled on PHP 8.4+ forward profile');
    }
}
?>
--FILE--
<?php
class Builder {
    public function build(): string { return 'built'; }
}
echo new Builder()->build(), "\n";
--EXPECT_EXIT--
255
--EXPECTF--
%AParse error%Aunexpected token "->"%A
