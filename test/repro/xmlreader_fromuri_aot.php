<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::fromUri / fromStream leftover of fromString (#35900 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_xmlreader_fromUri / zim_xmlreader_fromStream
 *
 * User-script AOT tokenizes the compile-time URI (host PHP 8.2 has no factories).
 * Fixture must exist at compile time so fromUri can fold.
 */
$path = 'test/repro/xmlreader_fromuri_aot.xml';
$r = XMLReader::fromUri($path);
$r->read();
echo 'fromUri=', $r->name, "\n";
$h = fopen($path, 'r');
$r2 = XMLReader::fromStream($h);
$r2->read();
echo 'fromStream=', $r2->name, "\n";
fclose($h);
