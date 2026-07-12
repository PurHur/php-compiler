--TEST--
AOT: DOMDocument::loadHTML() user-script standalone (#17954)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadHTML('<p id="target">hello</p>');
echo "loadhtml_ok\n";
--EXPECT--
loadhtml_ok
