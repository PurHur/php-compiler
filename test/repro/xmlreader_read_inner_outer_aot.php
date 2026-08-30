<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::readInnerXml / readOuterXml leftover of fromString (#35908 / #27299 / #19411).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_readInnerXml / readOuterXml
 */
$r = XMLReader::fromString('<a><b>x</b></a>');
$r->read();
echo 'inner=', $r->readInnerXml(), "\n";
echo 'outer=', $r->readOuterXml(), "\n";
