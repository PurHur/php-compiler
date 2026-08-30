<?php
declare(strict_types=1);
/** AOT: XMLReader::lookupNamespace leftover of getAttributeNs (#35929 / #35924). */
$r = XMLReader::fromString('<root xmlns:a="urn:a"/>');
$r->read();
var_export($r->lookupNamespace('a'));
echo PHP_EOL;
var_export($r->lookupNamespace('missing'));
echo PHP_EOL;
