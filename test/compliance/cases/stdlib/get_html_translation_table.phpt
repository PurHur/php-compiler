--TEST--
stdlib get_html_translation_table() HTML_SPECIALCHARS and HTML_ENTITIES (issue #3637)
--FILE--
<?php
echo function_exists('get_html_translation_table') ? '1' : '0', "\n";
echo defined('HTML_SPECIALCHARS') && HTML_SPECIALCHARS === 0 ? '1' : '0', "\n";
echo defined('HTML_ENTITIES') && HTML_ENTITIES === 1 ? '1' : '0', "\n";
$t = get_html_translation_table(HTML_SPECIALCHARS, ENT_QUOTES);
echo $t['<'], "\n";
echo count($t) === 5 ? '1' : '0', "\n";
$t2 = get_html_translation_table(HTML_SPECIALCHARS, ENT_COMPAT);
echo count($t2) === 4 ? '1' : '0', "\n";
$t3 = get_html_translation_table(HTML_ENTITIES, ENT_QUOTES);
echo count($t3) >= 250 ? '1' : '0', "\n";
$euro = chr(0xe2) . chr(0x82) . chr(0xac);
echo $t3[$euro], "\n";
--EXPECT--
1
1
1
&lt;
1
1
1
&euro;
