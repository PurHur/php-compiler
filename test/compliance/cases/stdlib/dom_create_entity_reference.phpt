--TEST--
stdlib DOMDocument::createEntityReference() (#15240, ext/dom/document.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

$doc = new DOMDocument();
$ref = $doc->createEntityReference('amp');
echo (int) ($ref instanceof DOMEntityReference), "\n";
echo $ref->nodeType, "\n";
echo $ref->nodeName, "\n";
echo var_export($ref->nodeValue, true), "\n";
echo (int) ($ref->ownerDocument === $doc), "\n";
?>
--EXPECT--
1
5
amp
NULL
1
