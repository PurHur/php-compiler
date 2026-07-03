--TEST--
DOMNode::C14N()/C14NFile() inclusive canonical XML (#14409, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root xmlns="http://example.com"><child>text</child></root>');
$expected = '<root xmlns="http://example.com"><child>text</child></root>';
$c14n = $doc->documentElement->C14N();
echo ($expected === $c14n) ? 'c14n ' : 'c14n-fail ';
$tmp = tempnam(sys_get_temp_dir(), 'c14n');
$bytes = $doc->documentElement->C14NFile($tmp);
echo (is_int($bytes) && $bytes === strlen($expected)) ? 'file ' : 'file-fail ';
echo ($expected === file_get_contents($tmp)) ? "body\n" : "body-fail\n";
@unlink($tmp);
?>
--EXPECT--
c14n file body
