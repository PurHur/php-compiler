<?php
declare(strict_types=1);
/**
 * AOT: XMLReader::fromUri leftover of fromString (#35900 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_xmlreader_fromUri
 */
$p = __DIR__.'/fixtures/xmlreader_mini.xml';
$r = XMLReader::fromUri($p);
while ($r->read()) {
    if (XMLReader::ELEMENT === $r->nodeType) {
        echo $r->name, "\n";
        break;
    }
}
