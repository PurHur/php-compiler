--TEST--
AOT: DOMDocument::loadHTMLFile() user-script standalone (#18734)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadHTMLFile('/tmp/dom_loadhtmlfile_aot_fixture.html');
echo "loadhtmlfile_ok\n";
--EXPECT--
loadhtmlfile_ok
