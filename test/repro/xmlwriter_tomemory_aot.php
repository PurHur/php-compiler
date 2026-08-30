<?php
declare(strict_types=1);

/**
 * AOT: XMLWriter::toMemory leftover of openMemory (#35890 / #19606).
 * php-src: ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_toMemory
 *
 * PHP 8.4 static factory — host fold via new XMLWriter + openMemory (host has no toMemory).
 */
$w = XMLWriter::toMemory();
$w->startDocument('1.0');
$w->writeElement('hi', 'there');
$w->endDocument();
echo $w->outputMemory();
