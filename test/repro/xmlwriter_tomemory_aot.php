<?php
declare(strict_types=1);

/**
 * AOT: XMLWriter::toMemory leftover of openMemory (#19606 / #35872).
 * php-src: ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_toMemory
 *
 * Requires PHP_COMPILER_PROFILE=8.4 (factories withheld on 8.4.0-dev / 8.2 gate).
 */
$w = XMLWriter::toMemory();
$w->startDocument('1.0', 'UTF-8');
$w->startElement('root');
$w->writeElement('child', 'hi');
$w->endElement();
$w->endDocument();
echo $w->outputMemory();
