--TEST--
stdlib XSLTProcessor::registerPHPFunctionNS() — namespaced XSLT callbacks (#22243, ext/xsl/xsltprocessor.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!extension_loaded('xsl') || !extension_loaded('dom')) {
    echo 'skip';
}
?>
--FILE--
<?php
$p = new XSLTProcessor();
echo 'has=', method_exists($p, 'registerPHPFunctionNS') ? '1' : '0', "\n";
try {
    $p->registerPHPFunctionNS('http://php.net/xsl', 'strlen', 'strlen');
    echo "reserved_ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $p->registerPHPFunctionNS('urn:foo', 'x:a', 'strlen');
    echo "ncname_ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $p->registerPHPFunctionNS('urn:foo', 'strlen', 123);
    echo "callable_ok\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$p->registerPHPFunctionNS('urn:foo', 'strlen', 'strlen');
echo "reg_ok\n";
?>
--EXPECT--
has=1
XSLTProcessor::registerPHPFunctionNS(): Argument #1 ($namespaceURI) must not be "http://php.net/xsl" because it is reserved by PHP
XSLTProcessor::registerPHPFunctionNS(): Argument #2 ($name) must be a valid callback name
XSLTProcessor::registerPHPFunctionNS(): Argument #3 ($callable) must be of type callable, int given
reg_ok
