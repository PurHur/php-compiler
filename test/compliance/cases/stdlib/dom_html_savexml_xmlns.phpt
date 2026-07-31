--TEST--
stdlib Dom\HTMLDocument::saveXml emits default XHTML xmlns (#26025, ext/dom/html_document.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
../../../repro/issue_dom_html_savexml_xmlns.php
--EXPECT--
ok
