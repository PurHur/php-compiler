<?php
declare(strict_types=1);
/** AOT: XMLReader::moveToAttribute leftover of getAttribute (#35940 / #35941 / #27299). */
$r = XMLReader::fromString('<root id="1" x="2"/>');
$r->read();
var_export($r->moveToAttribute('x'));
echo PHP_EOL;
echo $r->name, ' ', $r->value, ' ', $r->nodeType, PHP_EOL;
var_export($r->moveToElement());
echo PHP_EOL;
echo $r->name, PHP_EOL;
var_export($r->moveToAttribute('missing'));
echo PHP_EOL;
echo $r->name, PHP_EOL;
$r2 = XMLReader::fromString('<root id="42"/>');
$r2->read();
var_export($r2->moveToAttribute('id'));
echo PHP_EOL;
echo $r2->name, ' ', $r2->value, ' ', $r2->nodeType, PHP_EOL;
