<?php
// AOT Dom\HTMLDocument::$doctype after createFromString (#28940, re-#27300)
$doc = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body><p id="x">hi</p></body></html>');
echo 'doctype_null=', $doc->doctype === null ? '1' : '0', "\n";
echo 'doctype_name=', is_object($doc->doctype) ? $doc->doctype->name : 'n/a', "\n";
echo 'body=', $doc->body->textContent, "\n";

$frag = Dom\HTMLDocument::createFromString('<p>yo</p>');
echo 'frag_doctype_null=', $frag->doctype === null ? '1' : '0', "\n";
echo 'frag_body=', $frag->body->textContent, "\n";
