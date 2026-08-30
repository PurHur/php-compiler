<?php
declare(strict_types=1);
/** AOT: XMLReader::moveToFirstAttribute leftover of moveToAttribute (#35948 / #35941). */
$r = XMLReader::fromString('<root id="1" x="2"/>');
$r->read();
var_export($r->moveToFirstAttribute());
echo PHP_EOL;
var_export($r->name);
echo PHP_EOL;
var_export($r->value);
echo PHP_EOL;
var_export($r->nodeType);
echo PHP_EOL;
$empty = XMLReader::fromString('<root/>');
$empty->read();
var_export($empty->moveToFirstAttribute());
echo PHP_EOL;
var_export($empty->name);
echo PHP_EOL;
