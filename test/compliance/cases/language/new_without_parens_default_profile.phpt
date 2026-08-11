--TEST--
Language: `new Class()->method()` works on default 8.4.0-dev profile (#30207, re-#24883 / #24755, Zend/zend_language_parser.y)
--SKIPIF--
<?php
// Explicit PROFILE < 8.4 disables dereferencable new — separate test covers that case.
$raw = getenv('PHP_COMPILER_PROFILE');
if (is_string($raw) && '' !== trim($raw)) {
    $v = trim($raw);
    if (preg_match('/^\d+\.\d+$/', $v)) {
        $v .= '.0';
    }
    if (version_compare($v, '8.4.0', '<')) {
        die('skip dereferencable new disabled on pre-8.4 forward profile');
    }
}
?>
--FILE--
<?php
class Builder {
    public function build(): string { return 'built'; }
}
echo new Builder()->build(), "\n";
--EXPECT--
built
