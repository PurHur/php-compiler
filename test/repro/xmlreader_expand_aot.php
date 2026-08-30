<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::expand() leftover of fromString/open (#35911 / #27299 / #19394).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_expand
 */
$r = XMLReader::open('test/repro/fixtures/xmlreader_mini.xml');
$r->read();
$n = $r->expand();
echo get_class($n), ':', $n->nodeName, PHP_EOL;
