<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::readString leftover of fromString/readInnerXml (#27299 / #19411).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_readString
 */
$r = XMLReader::fromString('<a>hi<b>x</b></a>');
$r->read();
echo 'str=', $r->readString(), "\n";
