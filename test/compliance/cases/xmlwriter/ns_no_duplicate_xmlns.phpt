--TEST--
XMLWriter startElementNS+writeAttributeNS same prefix — one xmlns (#20324)
--FILE--
<?php
$o = new XMLWriter();
$o->openMemory();
$o->startDocument('1.0');
$o->startElementNS('ex', 'root', 'http://example.com');
$o->writeAttributeNS('ex', 'id', 'http://example.com', '1');
$o->endElement();
$o->endDocument();
$out = $o->outputMemory();
echo $out, "\n";
echo 'dup_xmlns=', (substr_count($out, 'xmlns:ex=') > 1 ? '1' : '0'), "\n";
?>
--EXPECT--
<?xml version="1.0"?>
<ex:root xmlns:ex="http://example.com" ex:id="1"/>

dup_xmlns=0
