<?php

declare(strict_types=1);

/**
 * Issue #6135 repro — XMLReader::open/read/getAttribute pull parser.
 */
file_put_contents('/tmp/xr_repro.xml', '<root><item id="1">a</item></root>');
$reader = XMLReader::open('/tmp/xr_repro.xml');
if (false === $reader) {
    echo "open_failed\n";
    exit(1);
}
$names = [];
while ($reader->read()) {
    if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'item') {
        $names[] = $reader->getAttribute('id');
    }
}
$reader->close();
echo implode(',', $names), "\n";
