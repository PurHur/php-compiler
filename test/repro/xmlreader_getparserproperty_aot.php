<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::getParserProperty leftover of fromString/read (#27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_getParserProperty
 */
$r = XMLReader::fromString('<root/>');
var_export($r->getParserProperty(XMLReader::LOADDTD));
echo PHP_EOL;
var_export($r->getParserProperty(XMLReader::DEFAULTATTRS));
echo PHP_EOL;
var_export($r->getParserProperty(XMLReader::VALIDATE));
echo PHP_EOL;
var_export($r->getParserProperty(XMLReader::SUBST_ENTITIES));
echo PHP_EOL;
$r->read();
var_export($r->getParserProperty(XMLReader::LOADDTD));
echo PHP_EOL;
