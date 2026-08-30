<?php
declare(strict_types=1);

/**
 * AOT: XMLReader::moveToElement leftover of moveToAttribute (#35940 / #27299).
 * php-src: ext/xmlreader/php_xmlreader.c zim_XMLReader_moveToElement
 */
$r = XMLReader::fromString('<root id="1" x="2"/>');
$r->read();
echo 'mta=', var_export($r->moveToAttribute('x'), true), ' val=', var_export($r->value, true), "\n";
echo 'mte=', var_export($r->moveToElement(), true), ' name=', $r->name, ' nt=', $r->nodeType, "\n";
echo 'mte2=', var_export($r->moveToElement(), true), "\n";
