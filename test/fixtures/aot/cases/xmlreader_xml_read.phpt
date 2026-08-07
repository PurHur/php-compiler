--TEST--
XMLReader::XML + read() walk — user-script AOT (#28670, re-#27299)
--FILE--
<?php
$ELEMENT = XMLReader::ELEMENT;
$xml = "<?xml version=\"1.0\"?><r><a>1</a></r>";
$reader = XMLReader::XML($xml);
while ($reader->read()) {
    if ($reader->nodeType === $ELEMENT) {
        echo $reader->name, "\n";
    }
}
--EXPECT--
r
a
