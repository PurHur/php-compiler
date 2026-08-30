<?php
declare(strict_types=1);
/** AOT: XMLReader::next leftover of fromString/read (#35926 / #27299). */
$r = XMLReader::fromString('<root><a/><b/></root>');
$r->read();
$r->read();
var_export($r->next());
echo PHP_EOL;
var_export($r->name);
echo PHP_EOL;
$r2 = XMLReader::fromString('<root><a/><b/><c/></root>');
$r2->read();
$r2->read();
var_export($r2->next('c'));
echo PHP_EOL;
var_export($r2->name);
echo PHP_EOL;
