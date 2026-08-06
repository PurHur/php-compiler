--TEST--
XMLReader::fromString + read() walk — user-script AOT (#27299)
--FILE--
<?php
// Hoist class consts: AOT class-const fetch inside loops is empty for seeded
// external classes (same gap as ArrayObject::ARRAY_AS_PROPS). Values match Zend.
$ELEMENT = XMLReader::ELEMENT;
$TEXT = XMLReader::TEXT;
$r = XMLReader::fromString('<root><a>1</a></root>');
$saw = false;
while ($r->read()) {
    if ($r->nodeType === $ELEMENT && $r->name === 'a') {
        echo 'elem=', $r->name, "\n";
        $saw = true;
    }
    if ($r->nodeType === $TEXT) {
        echo 'text=', $r->value, "\n";
    }
}
echo $saw ? "ok\n" : "miss\n";
--EXPECT--
elem=a
text=1
ok
