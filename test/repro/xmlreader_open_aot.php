<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::open leftover of fromUri/fromString (#35907 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_xmlreader_open
 */
$p = __DIR__.'/fixtures/xmlreader_mini.xml';
$r = XMLReader::open($p);
if (false === $r) {
    echo "open_failed\n";
    exit(1);
}
while ($r->read()) {
    if (XMLReader::ELEMENT === $r->nodeType) {
        echo $r->name, "\n";
        break;
    }
}
