--TEST--
stdlib Dom\HTML_NO_DEFAULT_NS — createFromString/File omit default XHTML ns (#26008, ext/dom/php_dom.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
../../../repro/dom_html_no_default_ns.php
--EXPECT--
ok
