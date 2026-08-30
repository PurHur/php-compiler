<?php
declare(strict_types=1);
/** AOT: XMLReader::next leftover of fromString/read (#35926 / #27299). */
$r = XMLReader::fromString('<root><a/><b/><c/></root>');
var_export($r->read());
echo '|', $r->name, PHP_EOL;
var_export($r->read());
echo '|', $r->name, PHP_EOL;
var_export($r->next());
echo '|', $r->name, PHP_EOL;
var_export($r->next('c'));
echo '|', $r->name, PHP_EOL;
var_export($r->next());
echo '|', $r->name, PHP_EOL;
