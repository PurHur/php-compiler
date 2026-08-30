<?php
declare(strict_types=1);
/** AOT: XMLReader::getAttributeNs leftover of getAttribute (#35925 / #35918). */
$r = XMLReader::fromString('<root xmlns:a="urn:a" a:id="42"/>');
$r->read();
var_export($r->getAttributeNs('id', 'urn:a'));
echo PHP_EOL;
var_export($r->getAttributeNs('id', 'urn:missing'));
echo PHP_EOL;
