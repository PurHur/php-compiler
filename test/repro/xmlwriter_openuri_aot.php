<?php
declare(strict_types=1);

/**
 * AOT: XMLWriter::openUri leftover of openMemory (#35872 / #19551).
 * php-src: ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_openUri
 *
 * User-script AOT folds openUri at compile time (writes the path on the host).
 * Echo only the bool return so VM/AOT stdout match; file side-effect is checked
 * by XmlWriterOpenUriAotTest after compile.
 */
$path = '/tmp/phpc_xw_openuri_aot.xml';
$w = new XMLWriter();
$ok = $w->openUri($path);
$w->startDocument('1.0');
$w->writeElement('hi', 'there');
$w->endDocument();
echo 'open=', var_export($ok, true), "\n";
