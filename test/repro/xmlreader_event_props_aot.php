<?php
declare(strict_types=1);
/** #35983 — XMLReader virtual props after read() under AOT (ext/xmlreader/php_xmlreader.c). */
$r = XMLReader::XML('<root id="1"/>');
$r->read();
echo $r->depth, '|', $r->localName, '|', $r->prefix, '|', $r->namespaceURI, '|';
echo (int) $r->attributeCount, '|', ($r->hasAttributes ? '1' : '0'), '|';
echo ($r->hasValue ? '1' : '0'), '|', ($r->isEmptyElement ? '1' : '0'), '|', $r->xmlLang, "\n";
$r->moveToFirstAttribute();
echo $r->depth, '|', $r->localName, '|', $r->name, '|', $r->value, "\n";
