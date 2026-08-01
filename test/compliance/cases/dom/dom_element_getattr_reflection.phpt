--TEST--
Dom\Element getAttribute/getAttributeNode Reflection nullable returns (#26065, ext/dom/php_dom.stub.php)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\ living API requires PHP_COMPILER_PROFILE=8.4 (#26065)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$d = Dom\HTMLDocument::createEmpty();
$el = $d->createElement('div');
foreach (['getAttribute', 'getAttributeNS', 'getAttributeNode', 'getAttributeNodeNS'] as $m) {
    $rm = new ReflectionMethod($el, $m);
    $t = $rm->getReturnType();
    echo $m, ' ret=', $t ? $t->__toString() : '(none)',
        ' allowsNull=', ($t && $t->allowsNull()) ? '1' : '0',
        "\n";
}
--EXPECT--
getAttribute ret=?string allowsNull=1
getAttributeNS ret=?string allowsNull=1
getAttributeNode ret=?Dom\Attr allowsNull=1
getAttributeNodeNS ret=?Dom\Attr allowsNull=1
