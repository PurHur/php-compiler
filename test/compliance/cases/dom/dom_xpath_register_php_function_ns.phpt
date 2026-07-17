--TEST--
ext/dom DOMXPath::registerPhpFunctionNS() — namespaced XPath callbacks (#20119, ext/dom/xpath.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadHTML('<a href="https://PHP.net">hello</a>');
$xp = new DOMXPath($doc);
echo 'has=', method_exists($xp, 'registerPhpFunctionNS') ? '1' : '0', "\n";
try {
    $xp->registerPhpFunctionNS('http://php.net/xpath', 'strtolower', 'strtolower');
    echo "reserved_ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $xp->registerPhpFunctionNS('urn:foo', 'x:a', 'strtolower');
    echo "ncname_ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
$xp->registerNamespace('foo', 'urn:foo');
$xp->registerPhpFunctionNS('urn:foo', 'strtolower', 'strtolower');
echo $xp->query('//a[foo:strtolower(string(@href)) = "https://php.net"]')->length, "\n";
$xp->registerNamespace('bar', 'urn:bar');
$xp->registerPhpFunctionNS('urn:bar', 'lower', 'strtolower');
echo $xp->query('//a[bar:lower(string(@href)) = "https://php.net"]')->length, "\n";
?>
--EXPECT--
has=1
DOMXPath::registerPhpFunctionNS(): Argument #1 ($namespaceURI) must not be "http://php.net/xpath" because it is reserved by PHP
DOMXPath::registerPhpFunctionNS(): Argument #2 ($name) must be a valid callback name
1
1
