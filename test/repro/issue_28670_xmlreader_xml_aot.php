<?php
// #28670 — AOT XMLReader::XML() + read() (re-#27299)
$ELEMENT = XMLReader::ELEMENT;
$xml = "<?xml version=\"1.0\"?><r><a>1</a></r>";
$reader = XMLReader::XML($xml);
while ($reader->read()) {
    if ($reader->nodeType === $ELEMENT) {
        echo $reader->name, "\n";
    }
}
