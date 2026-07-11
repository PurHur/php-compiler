--TEST--
AOT: DOMDocument::loadHTML()/getElementById() user-script standalone (#17954)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadHTML('<p id="target">hello</p>');
$found = $doc->getElementById('target');
echo null !== $found ? $found->textContent : 'null', "\n";
echo null === $doc->getElementById('missing') ? 'missing_null' : 'missing_found', "\n";
--EXPECT--
hello
missing_null
