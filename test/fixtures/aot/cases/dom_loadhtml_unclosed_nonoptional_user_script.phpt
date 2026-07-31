--TEST--
AOT: loadHTML unclosed non-optional tag getElementById (#25988)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadHTML('<div id="x">');
$found = $doc->getElementById('x');
echo null !== $found ? $found->tagName : 'null', "\n";
echo null === $doc->getElementById('missing') ? 'missing_null' : 'missing_found', "\n";
--EXPECT--
div
missing_null
