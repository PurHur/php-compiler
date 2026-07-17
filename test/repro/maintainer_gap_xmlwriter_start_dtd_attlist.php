<?php

declare(strict_types=1);

/**
 * Maintainer gap repro named in #20025 — streaming ATTLIST via start/end pair.
 */
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0');
echo 'startDtdAttlist=', method_exists($w, 'startDtdAttlist') ? '1' : '0', "\n";
echo 'endDtdAttlist=', method_exists($w, 'endDtdAttlist') ? '1' : '0', "\n";
$w->startDtd('root');
$w->startDtdAttlist('root');
$w->text('id ID #REQUIRED');
$w->endDtdAttlist();
$w->endDtd();
$w->startElement('root');
$w->endElement();
echo $w->outputMemory();
