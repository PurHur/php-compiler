<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::moveToAttributeNs leftover of moveToAttribute (#35941 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_moveToAttributeNs
 */
$r = XMLReader::fromString('<root xmlns:ns="urn:x" ns:b="2" a="1"/>');
$r->read();
var_export($r->moveToAttributeNs('b', 'urn:x'));
echo PHP_EOL;
var_export($r->name);
echo PHP_EOL;
var_export($r->value);
echo PHP_EOL;
var_export($r->nodeType);
echo PHP_EOL;
var_export($r->moveToAttributeNs('b', 'urn:other'));
echo PHP_EOL;
var_export($r->name);
echo PHP_EOL;
var_export($r->value);
echo PHP_EOL;
