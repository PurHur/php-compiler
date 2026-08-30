<?php
declare(strict_types=1);
/** AOT: XMLReader::moveToNextAttribute leftover of moveToAttribute (#35952 / #35941). */
$r = XMLReader::fromString('<root id="1" x="2"/>');
$r->read();
// From element → first attribute (php-src moveToNextAttribute).
var_export($r->moveToNextAttribute());
echo PHP_EOL;
var_export($r->name);
echo PHP_EOL;
var_export($r->value);
echo PHP_EOL;
var_export($r->nodeType);
echo PHP_EOL;
var_export($r->moveToNextAttribute());
echo PHP_EOL;
var_export($r->name);
echo PHP_EOL;
var_export($r->value);
echo PHP_EOL;
var_export($r->moveToNextAttribute());
echo PHP_EOL;
var_export($r->name);
echo PHP_EOL;
