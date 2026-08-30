<?php
declare(strict_types=1);
/** AOT: XMLReader::getAttribute leftover of fromString/read (#35918 / #27299). */
$r = XMLReader::fromString('<root id="42"/>');
$r->read();
var_export($r->getAttribute('id'));
echo PHP_EOL;
var_export($r->getAttribute('missing'));
echo PHP_EOL;
