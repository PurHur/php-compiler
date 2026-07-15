--TEST--
AOT: DOMDocument::loadHTML() NOIMPLIED|NODEFDTD fragment (#19090)
--FILE--
<?php
$doc = new DOMDocument();
// User-script AOT: compile-time int bitmask (named OR folds on VM/JIT compliance).
$doc->loadHTML('<p>hi</p>', 8196);
echo $doc->saveHTML();
--EXPECT--
<p>hi</p>

