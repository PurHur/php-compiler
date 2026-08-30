<?php
declare(strict_types=1);

/**
 * AOT: XMLWriter::toStream leftover of toMemory/toUri (#35895 / #19606).
 * php-src: ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_toStream
 *
 * Host fold uses fopen literal path → openUri (host PHP 8.2 has no toStream).
 */
$path = '/tmp/phpc_xw_tostream_aot.xml';
@unlink($path);
$s = fopen($path, 'w');
$w = XMLWriter::toStream($s);
$w->startDocument('1.0');
$w->writeElement('hi', 'there');
$w->endDocument();
fclose($s);
echo "ok\n";
