--TEST--
Language: final promoted ctor param is Parse error on PROFILE=8.2 (#31153, Zend/zend_language_parser.y)
--SKIPIF--
<?php
// Key off PROFILE env — not supportsFinalPromotedProperties(). If the 8.4 compile
// fatal leaked onto 8.2, skipping would hide the regression (#31153).
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
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
class C { public function __construct(final public int $x) {} }
echo (new C(1))->x, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Parse error:  syntax error, unexpected token "final", expecting variable in %s on line %d
