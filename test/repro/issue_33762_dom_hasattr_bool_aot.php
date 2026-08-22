<?php
// #33762 — AOT hasAttribute/hasAttributeNS must return bool like Zend (ext/dom/element.c).
$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
$e = $d->documentElement;
var_dump($e->hasAttribute('a'));
var_dump($e->hasAttribute('missing'));
var_dump($e->hasAttributeNS(null, 'a'));
var_dump($e->hasAttributeNS(null, 'missing'));
