--TEST--
xmlreader XMLReader::readInnerXml/readOuterXml/readString (#19411, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
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

$r2 = new XMLReader();
$r2->XML('<root><c>t</c></root>');
while ($r2->read()) {
    if ($r2->nodeType === XMLReader::ELEMENT && $r2->localName === 'c') {
        echo 'c_inner=', $r2->readInnerXml(), "\n";
        echo 'c_outer=', $r2->readOuterXml(), "\n";
        echo 'c_str=', $r2->readString(), "\n";
        break;
    }
}

$r3 = new XMLReader();
$r3->XML('<root/>');
while ($r3->read()) {
    if ($r3->nodeType === XMLReader::ELEMENT) {
        echo 'e_inner=', var_export($r3->readInnerXml(), true), "\n";
        echo 'e_outer=', $r3->readOuterXml(), "\n";
        echo 'e_str=', var_export($r3->readString(), true), "\n";
        break;
    }
}

$r4 = new XMLReader();
$r4->XML('<r xmlns="urn:d"><c/></r>');
while ($r4->read()) {
    if ($r4->nodeType === XMLReader::ELEMENT && $r4->localName === 'r') {
        echo 'ns_inner=', $r4->readInnerXml(), "\n";
        echo 'ns_outer=', $r4->readOuterXml(), "\n";
        break;
    }
}

$r5 = new XMLReader();
echo 'empty=', var_export($r5->readInnerXml(), true), "\n";
?>
--EXPECT--
inner=<c>t</c>
outer=<root><c>t</c></root>
str=t
c_inner=t
c_outer=<c>t</c>
c_str=t
e_inner=''
e_outer=<root/>
e_str=''
ns_inner=<c xmlns="urn:d"/>
ns_outer=<r xmlns="urn:d"><c/></r>
empty=''
