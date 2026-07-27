--TEST--
DOMDocument::saveHTML preserves named HTML entities (#23684)
--FILE--
<?php
$doc = new DOMDocument();
@$doc->loadHTML('<p>&eacute;x&nbsp;y</p>');
$html = $doc->saveHTML();
echo str_contains($html, '&eacute;') ? 'eacute=entity' : 'eacute=other', "\n";
echo str_contains($html, '&nbsp;') ? 'nbsp=entity' : 'nbsp=other', "\n";
echo 'textContent=' . bin2hex($doc->getElementsByTagName('p')->item(0)->textContent) . "\n";
--EXPECT--
eacute=entity
nbsp=entity
textContent=c3a978c2a079
