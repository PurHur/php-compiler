<?php
// Repro for #19411 — XMLReader::readInnerXml / readOuterXml / readString
$r = new XMLReader();
$r->XML('<root><c>t</c></root>');
while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'root') {
        echo 'inner=', $r->readInnerXml(), "\n";
        echo 'outer=', $r->readOuterXml(), "\n";
        echo 'str=', $r->readString(), "\n";
        break;
    }
}
