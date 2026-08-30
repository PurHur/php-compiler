<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::getAttributeNs/getAttributeNo leftover of getAttribute (#35924 / #35918).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_getAttributeNs / getAttributeNo
 */
$r = XMLReader::fromString('<root xmlns:ns="urn:x" ns:b="2" a="1"/>');
while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'root') {
        var_export($r->getAttribute('a'));
        echo "\n";
        var_export($r->getAttributeNs('b', 'urn:x'));
        echo "\n";
        var_export($r->getAttributeNo(0));
        echo "\n";
        var_export($r->getAttributeNs('b', 'urn:other'));
        echo "\n";
        var_export($r->getAttributeNo(99));
        echo "\n";
        break;
    }
}
