<?php
declare(strict_types=1);
/** AOT: XMLReader::moveToAttributeNo leftover of moveToAttribute (#35946 / #35941). */
$r = XMLReader::fromString('<root id="1" x="2"/>');
$r->read();
var_export($r->moveToAttributeNo(1));
echo PHP_EOL;
var_export($r->name);
echo PHP_EOL;
var_export($r->value);
echo PHP_EOL;
var_export($r->nodeType);
echo PHP_EOL;
var_export($r->moveToAttributeNo(99));
echo PHP_EOL;
var_export($r->name);
echo PHP_EOL;
