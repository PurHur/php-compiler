<?php
// AOT: XMLReader virtual props after read() / moveToFirstAttribute (#35983 leftover of #27299).
$r = new XMLReader();
$r->XML('<root id="1"><child>t</child></root>');
$r->read();
echo 'depth=', $r->depth, "\n";
echo 'localName=', $r->localName, "\n";
echo 'prefix=', $r->prefix, "\n";
echo 'ns=', $r->namespaceURI, "\n";
echo 'attrCount=', $r->attributeCount, "\n";
echo 'hasAttrs=', (int) $r->hasAttributes, "\n";
echo 'hasValue=', (int) $r->hasValue, "\n";
echo 'empty=', (int) $r->isEmptyElement, "\n";
echo 'xmlLang=', $r->xmlLang, "\n";
echo 'name=', $r->name, "\n";
$r->moveToFirstAttribute();
echo 'attrDepth=', $r->depth, "\n";
echo 'attrLocal=', $r->localName, "\n";
echo 'attrName=', $r->name, "\n";
echo 'attrValue=', $r->value, "\n";
echo 'attrHasValue=', (int) $r->hasValue, "\n";
$r->moveToElement();
$r->read();
echo 'childDepth=', $r->depth, "\n";
echo 'childLocal=', $r->localName, "\n";
