--TEST--
DOMDocument::loadHTML() bare UTF-8 uses ISO-8859-1 (#22023, ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
@$d->loadHTML('<p>café</p>');
$bare = $d->getElementsByTagName('p')->item(0)->textContent;
echo 'bare_hex=', bin2hex($bare), "\n";
echo 'bare_enc=', var_export($d->encoding, true), "\n";

$d2 = new DOMDocument();
@$d2->loadHTML('<meta charset="utf-8"><p>café</p>');
$meta = $d2->getElementsByTagName('p')->item(0)->textContent;
echo 'meta_hex=', bin2hex($meta), "\n";
echo 'meta_enc=', var_export($d2->encoding, true), "\n";

$d3 = new DOMDocument();
@$d3->loadHTML('<?xml encoding="utf-8"><p>café</p>');
$xml = $d3->getElementsByTagName('p')->item(0)->textContent;
echo 'xml_hex=', bin2hex($xml), "\n";
--EXPECT--
bare_hex=636166c383c2a9
bare_enc=NULL
meta_hex=636166c3a9
meta_enc='utf-8'
xml_hex=636166c3a9
