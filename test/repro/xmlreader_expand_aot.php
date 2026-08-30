<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::expand leftover of fromString/open (#35911 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_expand
 */
$p = __DIR__.'/fixtures/xmlreader_mini.xml';
$r = XMLReader::open($p);
if (false === $r) {
    echo "open_failed\n";
    exit(1);
}
while ($r->read()) {
    if (XMLReader::ELEMENT === $r->nodeType) {
        $n = $r->expand();
        if (false === $n) {
            echo "expand_false\n";
            exit(1);
        }
        echo get_class($n), ':', $n->nodeName, "\n";
        break;
    }
}
