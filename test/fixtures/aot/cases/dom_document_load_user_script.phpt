--TEST--
AOT: DOMDocument::load() user-script standalone (#18897)
--FILE--
<?php
file_put_contents('/tmp/dom_document_load_aot_fixture.xml', '<root><child/></root>');
$doc = new DOMDocument();
$doc->load('/tmp/dom_document_load_aot_fixture.xml');
echo "load_ok\n";
@unlink('/tmp/dom_document_load_aot_fixture.xml');
--EXPECT--
load_ok
