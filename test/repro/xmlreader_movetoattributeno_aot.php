<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::moveToAttributeNo leftover of moveToAttribute (#35946 / #35941 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_moveToAttributeNo
 */
$r = XMLReader::fromString('<root id="1" x="2"/>');
$r->read();
echo 'name=', $r->name, "\n";
echo 'no1=', var_export($r->moveToAttributeNo(1), true), ' name=', var_export($r->name, true), ' val=', var_export($r->value, true), ' nt=', $r->nodeType, "\n";
$r->moveToElement();
echo 'oob=', var_export($r->moveToAttributeNo(9), true), ' name=', $r->name, ' nt=', $r->nodeType, "\n";
