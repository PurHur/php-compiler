<?php
/**
 * Issue #19308 repro — XMLReader::XML() in-memory open + element name walk.
 */
$r = new XMLReader();
$r->XML('<r><a>1</a></r>');
while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT) {
        echo $r->name, ':';
    }
}
echo PHP_EOL;
