--TEST--
Language: final promoted ctor param is Parse error on unset/≤8.3 reference profile (#31153, Zend/zend_language_parser.y)
--SKIPIF--
<?php
// Key off PROFILE env — not supportsFinalProperties(). If the gate wrongly
// returns true on the reference profile, skipping would hide the regression (#31153).
$raw = getenv('PHP_COMPILER_PROFILE');
if (is_string($raw) && '' !== trim($raw)) {
    $v = trim($raw);
    if (preg_match('/^\d+\.\d+$/', $v)) {
        $v .= '.0';
    }
    if (version_compare($v, '8.4.0', '>=')) {
        die('skip final-on-parameter grammar is 8.4+ (compile fatal) or 8.5+ (accept)');
    }
}
?>
--FILE--
<?php
class C { public function __construct(final public int $x) {} }
echo (new C(1))->x, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Parse error:  syntax error, unexpected token "final", expecting variable in %s on line %d
