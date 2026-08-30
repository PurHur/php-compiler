<?php
declare(strict_types=1);

/**
 * AOT: XMLWriter::toUri leftover of openUri (#19606 / #35872).
 * php-src: ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_toUri
 */
$path = '/tmp/phpc_xw_touri_aot.xml';
$w = XMLWriter::toUri($path);
$w->startDocument('1.0');
$w->writeElement('hi', 'there');
$w->endDocument();
echo "ok\n";
