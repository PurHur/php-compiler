<?php

// Non-strict: null coerces to '' → empty DOMNodeList (#29959).
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$list = $d->getElementsByTagName(null);
echo 'len=', $list->length, "\n";
echo "ok\n";
