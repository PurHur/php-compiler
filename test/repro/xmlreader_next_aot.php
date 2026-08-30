<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::next leftover of fromString/read (#35926 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_next
 */
$r = XMLReader::fromString('<root><a/><b>x</b><c/></root>');
$r->read(); // root
$r->read(); // a
echo 'next=', var_export($r->next(), true), ' name=', $r->name, "\n";
echo 'named=', var_export($r->next('c'), true), ' name=', $r->name, "\n";
