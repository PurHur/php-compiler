--TEST--
dom DOMDocument::loadHTML() decodes HTML character references in textContent (#20260)
--FILE--
<?php
$doc = new DOMDocument();
@$doc->loadHTML('<!DOCTYPE html><html><body><p title="A&amp;B caf&eacute; &lt;x&gt;">A&amp;B caf&eacute; &lt;x&gt;</p></body></html>');
$p = $doc->getElementsByTagName('p')->item(0);
$expected = "A&B café <x>";
echo 'text=', ($p->textContent === $expected) ? 'ok' : 'fail:'.json_encode($p->textContent), "\n";
echo 'title=', ($p->getAttribute('title') === $expected) ? 'ok' : 'fail:'.json_encode($p->getAttribute('title')), "\n";
--EXPECT--
text=ok
title=ok
