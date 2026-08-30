<?php
declare(strict_types=1);
/**
 * AOT: XMLReader::fromStream leftover of fromString (#35900 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_xmlreader_fromStream
 */
$p = __DIR__.'/fixtures/xmlreader_mini.xml';
$h = fopen($p, 'r');
$r = XMLReader::fromStream($h);
while ($r->read()) {
    if (XMLReader::ELEMENT === $r->nodeType) {
        echo $r->name, "\n";
        break;
    }
}
fclose($h);
