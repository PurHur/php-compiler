<?php
declare(strict_types=1);

/**
 * AOT: XMLWriter::toUri leftover of openUri (#35890 / #19606).
 * php-src: ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_toUri
 *
 * User-script AOT folds toUri at compile time (writes the path on the host).
 * Echo only a marker so VM/AOT stdout match; file side-effect is checked after compile.
 */
$path = '/tmp/phpc_xw_touri_aot.xml';
$w = XMLWriter::toUri($path);
$w->startDocument('1.0');
$w->writeElement('hi', 'there');
$w->endDocument();
echo "ok\n";
