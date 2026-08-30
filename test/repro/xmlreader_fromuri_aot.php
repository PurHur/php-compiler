<?php

declare(strict_types=1);

/**
 * AOT: XMLReader::fromUri leftover of fromString (#35900 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_xmlreader_fromUri
 *
 * XML must exist at compile time (user-script AOT tokenizes via file_get_contents).
 */
$path = '/tmp/phpc_xr_fromuri_aot.xml';
$reader = XMLReader::fromUri($path);
while ($reader->read()) {
    if ($reader->nodeType === XMLReader::ELEMENT) {
        echo $reader->name;
    }
}
echo "\n";
