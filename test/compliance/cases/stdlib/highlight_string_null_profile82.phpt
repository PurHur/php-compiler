--TEST--
stdlib highlight_string(null) — coerce on 8.2 profile (#20262, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
$r = highlight_string(null, true);
echo is_string($r) ? "string\n" : "not_string\n";
echo (false !== strpos($r, '<code>')) ? "html\n" : "no_html\n";
?>
--EXPECT--
string
html
