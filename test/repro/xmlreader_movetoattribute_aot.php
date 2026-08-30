<?php
declare(strict_types=1);
/** AOT: XMLReader::moveToAttribute leftover of getAttribute (#35941 / #35918). */
$r = XMLReader::fromString('<root id="42"/>');
$r->read();
var_export($r->moveToAttribute('id'));
echo PHP_EOL;
var_export($r->name);
echo PHP_EOL;
var_export($r->value);
echo PHP_EOL;
var_export($r->nodeType);
echo PHP_EOL;
var_export($r->moveToAttribute('missing'));
echo PHP_EOL;
var_export($r->name);
echo PHP_EOL;
