--TEST--
ext/uri: Uri\Rfc3986\Uri::parse basic absolute URL (#9051)
--FILE--
<?php
declare(strict_types=1);

if (!extension_loaded('uri')) {
    echo "skip: ext/uri not loaded\n";
    exit(0);
}

$u = \Uri\Rfc3986\Uri::parse('https://example.com/path?q=1');
var_export($u?->getHost());
echo "\n";
var_export($u?->getPath());
echo "\n";
?>
--EXPECT--
'example.com'
'/path'
