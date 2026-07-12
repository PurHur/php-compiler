<?php

declare(strict_types=1);

/**
 * Issue #6065 repro — XMLWriter openMemory streaming API.
 */
var_export(class_exists('XMLWriter', false));
echo "\n";
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0', 'UTF-8');
$w->startElement('root');
$w->text('hi');
$w->endElement();
echo $w->outputMemory(), "\n";
