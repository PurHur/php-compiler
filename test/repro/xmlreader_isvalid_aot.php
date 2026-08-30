<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::isValid leftover of fromString/read (#27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_isValid
 */
$r = XMLReader::fromString('<root/>');
var_export($r->isValid());
echo PHP_EOL;
$r->read();
var_export($r->isValid());
echo PHP_EOL;
