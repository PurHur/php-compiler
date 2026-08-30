<?php
declare(strict_types=1);
/** AOT: XMLReader::close leftover of fromString/open (#35935 / #27299). */
$r = XMLReader::fromString('<root/>');
$r->read();
var_export($r->close());
echo PHP_EOL;
