<?php
/** Maintainer gap: XMLReader::getAttributeNs/getAttributeNo (#19412). */
$r = new XMLReader();
$r->XML('<root xmlns:ns="urn:x" ns:b="2" a="1"/>');
while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'root') {
        echo 'a=', var_export($r->getAttribute('a'), true), "\n";
        echo 'ns=', var_export($r->getAttributeNs('b', 'urn:x'), true), "\n";
        for ($i = 0; $i < $r->attributeCount; $i++) {
            echo 'no', $i, '=', var_export($r->getAttributeNo($i), true), "\n";
        }
        echo 'missing=', var_export($r->getAttributeNs('b', 'urn:other'), true), "\n";
        echo 'xmlnsDecl=', var_export($r->getAttributeNs('ns', 'http://www.w3.org/2000/xmlns/'), true), "\n";
        echo 'oob=', var_export($r->getAttributeNo(99), true), "\n";
        try {
            $r->getAttributeNs('', 'urn:x');
        } catch (ValueError $e) {
            echo 'emptyName=', $e->getMessage(), "\n";
        }
        try {
            $r->getAttributeNs('b', '');
        } catch (ValueError $e) {
            echo 'emptyNs=', $e->getMessage(), "\n";
        }
        break;
    }
}
