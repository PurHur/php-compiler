--TEST--
Dom\HTMLDocument SVG/MathML foreign-content namespaces (#26033, ext/dom/html5_parser.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
../../../repro/issue_26033_dom_html_foreign_namespaces.php
--EXPECT--
ok
