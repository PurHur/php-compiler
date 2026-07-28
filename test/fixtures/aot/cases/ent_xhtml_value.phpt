--TEST--
AOT: ENT_XHTML document-type constant is 32 (#24067)
--FILE--
<?php
echo ENT_XHTML, "\n";
echo ENT_XML1, "\n";
echo ENT_HTML5, "\n";
--EXPECT--
32
16
48
