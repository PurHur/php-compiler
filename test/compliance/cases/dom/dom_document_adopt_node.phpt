--TEST--
DOMDocument::adoptNode() moves node across documents — PHP 8.3+ (#19654, #24995, ext/dom/document.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
$d1 = new DOMDocument();
$d1->loadXML('<a><n>t</n></a>');
$d2 = new DOMDocument();
$d2->loadXML('<b/>');
$n = $d1->documentElement->firstChild;
$a = $d2->adoptNode($n);
echo $a->nodeName, "\n";
echo $d1->saveXML($d1->documentElement), "\n";
echo ($n === $a) ? "same\n" : "clone\n";
echo ($a->ownerDocument === $d2) ? "owner-d2\n" : "owner-other\n";
echo (null === $a->parentNode) ? "detached\n" : "attached\n";
$d2->documentElement->appendChild($a);
echo $d2->saveXML($d2->documentElement), "\n";
try {
    $d2->adoptNode($d1);
    echo "adopted-doc\n";
} catch (DOMException $e) {
    echo $e->getCode() === DOMException::NOT_SUPPORTED_ERR ? "reject-doc\n" : ("other:".$e->getMessage()."\n");
}
?>
--EXPECT--
n
<a/>
same
owner-d2
detached
<b><n>t</n></b>
reject-doc
