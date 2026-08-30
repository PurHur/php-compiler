<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::setParserProperty leftover of fromString/read (#35965 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_setParserProperty
 */
$r = XMLReader::fromString('<root/>');
var_export($r->setParserProperty(XMLReader::LOADDTD, true));
echo PHP_EOL;
var_export($r->setParserProperty(XMLReader::DEFAULTATTRS, false));
echo PHP_EOL;
var_export($r->setParserProperty(XMLReader::VALIDATE, false));
echo PHP_EOL;
var_export($r->setParserProperty(XMLReader::SUBST_ENTITIES, true));
echo PHP_EOL;
$r->read();
var_export($r->setParserProperty(XMLReader::LOADDTD, false));
echo PHP_EOL;
