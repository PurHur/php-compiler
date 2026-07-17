<?php

declare(strict_types=1);

/**
 * Repro #19468 — XMLWriter DTD ELEMENT/ATTLIST/ENTITY methods.
 * Zend: ELEMENT/ATTLIST/ENTITY decls inside startDtd … endDtd.
 */
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0');
foreach (['writeDtdElement', 'writeDtdAttlist', 'startDtdEntity', 'endDtdEntity', 'writeDtdEntity'] as $m) {
    echo $m, '=', method_exists($w, $m) ? '1' : '0', "\n";
}
$w->startDtd('root');
var_export($w->writeDtdElement('foo', '(#PCDATA)'));
echo "\n";
var_export($w->writeDtdAttlist('foo', 'bar CDATA #IMPLIED'));
echo "\n";
var_export($w->startDtdEntity('ent', false));
echo "\n";
var_export($w->text('bar'));
echo "\n";
var_export($w->endDtdEntity());
echo "\n";
var_export($w->writeDtdEntity('ent2', 'val', false));
echo "\n";
$w->endDtd();
$w->startElement('root');
$w->endElement();
echo 'out=', $w->outputMemory(), "\n";
