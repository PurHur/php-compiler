<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::setSchema/setRelaxNGSchema* leftover of fromString (#35971 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_setSchema / setRelaxNGSchema*
 */
$r = XMLReader::fromString('<root/>');
var_export($r->setSchema(null));
echo PHP_EOL;
var_export($r->setRelaxNGSchema(null));
echo PHP_EOL;
var_export($r->setRelaxNGSchemaSource(null));
echo PHP_EOL;
