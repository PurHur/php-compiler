<?php
$d = new DOMDocument();
$d->loadXML('<r a="1"><c/></r>');
$el = $d->documentElement;
echo gettype($d->C14N()), '|', $d->C14N(), '|', gettype($el->C14N()), '|', $el->C14N();
