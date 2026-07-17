<?php
$w = new XMLWriter();
$w->openMemory();
$w->startDtd('root');
$w->writeDtdElement('foo', '(#PCDATA)');
$w->writeDtdAttlist('foo', 'bar CDATA #IMPLIED');
$w->startDtdEntity('ent', 0);
$w->text('bar');
$w->endDtdEntity();
$w->writeDtdEntity('ent2', 'val');
$w->endDtd();
$w->startElement('root');
$w->endElement();
echo $w->outputMemory(), "\n";
