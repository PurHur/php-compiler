--TEST--
get_html_translation_table() ENT_QUOTES|ENT_HTML5 flag expression (issue #11804)
--FILE--
<?php
$t = get_html_translation_table(HTML_ENTITIES, ENT_QUOTES | ENT_HTML5);
echo $t['"'] ?? '', "\n";
$t2 = get_html_translation_table(HTML_ENTITIES, 51);
echo $t2['"'] ?? '', "\n";
?>
--EXPECT--
&quot;
&quot;
