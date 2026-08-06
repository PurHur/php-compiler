<?php
// Maintainer gap: AOT XMLReader::fromString + read() (#27299)
// Hoist ELEMENT/TEXT — AOT class-const inside loops is a pre-existing empty-fetch
// gap for seeded external classes (ArrayObject::ARRAY_AS_PROPS same shape).
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
