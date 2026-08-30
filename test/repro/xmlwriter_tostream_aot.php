<?php
declare(strict_types=1);

/**
 * AOT: XMLWriter::toStream leftover of toMemory/toUri (#35895 / #19606).
 * php-src: ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_toStream
 *
 * User-script AOT folds toStream(fopen literal) as openUri at compile time
 * (host PHP 8.2 has no toStream). Echo only a marker so VM/AOT stdout match;
 * file side-effect is checked by XmlWriterToStreamAotTest after compile.
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
