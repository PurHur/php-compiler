--TEST--
stdlib get_html_translation_table() ENT_QUOTES apostrophe in single-arg and two-arg forms (issue #11443)
--FILE--
<?php
$table = get_html_translation_table(ENT_QUOTES | ENT_SUBSTITUTE);
echo $table["'"], "\n";
$t2 = get_html_translation_table(HTML_SPECIALCHARS, ENT_QUOTES);
echo $t2["'"], "\n";
echo count($t2) === 5 ? '1' : '0', "\n";
--EXPECT--
&#039;
&#039;
1
