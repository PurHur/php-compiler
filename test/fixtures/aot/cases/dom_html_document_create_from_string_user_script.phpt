--TEST--
AOT Dom\HTMLDocument::createFromString body textContent (#27300)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body><p>hi</p></body></html>');
echo $d->body->textContent, "\n";
$frag = Dom\HTMLDocument::createFromString('<p>yo</p>');
echo $frag->body->textContent, "\n";
--EXPECT--
hi
yo
