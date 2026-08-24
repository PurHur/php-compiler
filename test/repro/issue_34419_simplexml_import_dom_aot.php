<?php

// #34419 — AOT simplexml_import_dom after DOMDocument::loadXML (php-src ext/simplexml/simplexml.c)
$d = new DOMDocument();
$d->loadXML('<r a="1"><c/></r>');
$x = simplexml_import_dom($d);
echo $x->getName(), ':', (string) $x['a'], "\n";
echo "DONE\n";
