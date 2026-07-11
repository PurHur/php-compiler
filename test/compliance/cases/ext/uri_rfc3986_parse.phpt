--TEST--
ext/uri: Uri\Rfc3986\Uri::parse basic absolute URL (#9051)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsUri()) {
    die('skip ext/uri withheld on reference profile (#17830)');
}
--FILE--
<?php
declare(strict_types=1);

$u = \Uri\Rfc3986\Uri::parse('https://example.com/path?q=1');
var_export($u?->getHost());
echo "\n";
var_export($u?->getPath());
echo "\n";
?>
--EXPECT--
'example.com'
'/path'
