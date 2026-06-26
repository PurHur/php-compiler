--TEST--
Stdlib: get_html_translation_table() ENT_HTML5 full entity map (#12202, ext/standard/html.c)
--FILE--
<?php
$t = get_html_translation_table(HTML_ENTITIES, ENT_HTML5);
echo count($t), "\n";
echo $t["\xe2\x82\xac"] ?? 'missing', "\n";
?>
--EXPECT--
1509
&euro;
