--TEST--
AOT Dom\HTMLDocument::createFromString doctype pin (#28940)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body><p>hi</p></body></html>');
echo 'doctype_null=', $doc->doctype === null ? '1' : '0', "\n";
echo 'doctype_name=', is_object($doc->doctype) ? $doc->doctype->name : 'n/a', "\n";
echo 'body=', $doc->body->textContent, "\n";
$frag = Dom\HTMLDocument::createFromString('<p>yo</p>');
echo 'frag_doctype_null=', $frag->doctype === null ? '1' : '0', "\n";
echo 'frag_body=', $frag->body->textContent, "\n";
--EXPECT--
doctype_null=0
doctype_name=html
body=hi
frag_doctype_null=1
frag_body=yo
