<?php
// #36006 — XMLReader xmlLang must inherit xml:lang (php-src xmlTextReaderConstXmlLang).
$r = new XMLReader();
$r->XML('<root xml:lang="en"><child>t</child></root>');
$r->read();
echo 'rootLang=', $r->xmlLang, "\n";
$r->read();
echo 'childLang=', $r->xmlLang, "\n";
$r->read();
echo 'textLang=', $r->xmlLang, "\n";

$r2 = new XMLReader();
$r2->XML('<root xml:lang="en" id="1"/>');
$r2->read();
$r2->moveToFirstAttribute();
echo 'attrLang=', $r2->xmlLang, "\n";
