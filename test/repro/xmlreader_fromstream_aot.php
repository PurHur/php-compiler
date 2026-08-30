<?php

declare(strict_types=1);

/**
 * AOT: XMLReader::fromStream leftover of fromString (#35900 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_xmlreader_fromStream
 *
 * Recovers compile-time fopen path (#35895) and tokenizes the file.
 */
$path = '/tmp/phpc_xr_fromstream_aot.xml';
$h = fopen($path, 'r');
$reader = XMLReader::fromStream($h);
while ($reader->read()) {
    if ($reader->nodeType === XMLReader::ELEMENT) {
        echo $reader->name;
    }
}
fclose($h);
echo "\n";
