<?php
declare(strict_types=1);
/** AOT: XMLReader::moveToAttribute/moveToElement leftover of fromString/read (#35940 / #35941 / #27299). */
$r = XMLReader::fromString('<root id="1" x="2"/>');
$r->read();
var_export($r->moveToAttribute('x'));
echo PHP_EOL;
var_export($r->value);
echo PHP_EOL;
var_export($r->moveToElement());
echo PHP_EOL;
var_export($r->name);
echo PHP_EOL;
var_export($r->nodeType);
echo PHP_EOL;
var_export($r->moveToElement());
echo PHP_EOL;
var_export($r->moveToAttribute('missing'));
echo PHP_EOL;
