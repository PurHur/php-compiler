--TEST--
stdlib htmlspecialchars()/htmlentities() double_encode: named parameter (#10404, ext/standard/html.c)
--FILE--
<?php
echo htmlspecialchars('<a>', ENT_QUOTES | ENT_HTML5, 'UTF-8', double_encode: false), "\n";
echo htmlentities('<a>', ENT_QUOTES | ENT_HTML5, 'UTF-8', double_encode: false), "\n";
--EXPECT--
&lt;a&gt;
&lt;a&gt;
