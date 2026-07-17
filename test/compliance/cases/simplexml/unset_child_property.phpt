--TEST--
SimpleXMLElement unset child property and dimension (#19681, ext/simplexml/sxe.c)
--FILE--
<?php
$s = simplexml_load_string('<r><a>1</a><b>2</b></r>');
unset($s->a);
echo trim($s->asXML()), "\n";
$s2 = simplexml_load_string('<r><a>1</a><a>2</a></r>');
unset($s2->a[0]);
echo trim($s2->asXML()), "\n";
$s3 = simplexml_load_string('<r a="1"><b>2</b></r>');
unset($s3['a']);
echo 'attr=', isset($s3['a']) ? '1' : '0', "\n";
?>
--EXPECT--
<?xml version="1.0"?>
<r><b>2</b></r>
<?xml version="1.0"?>
<r><a>2</a></r>
attr=0
