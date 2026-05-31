--TEST--
AOT: get_html_translation_table() HTML_SPECIALCHARS / HTML_ENTITIES (issue #3637)
--FILE--
<?php
$t = get_html_translation_table(HTML_SPECIALCHARS, ENT_QUOTES);
echo $t['<'], "\n";
echo count($t) === 5 ? '1' : '0', "\n";
$t3 = get_html_translation_table(HTML_ENTITIES, ENT_QUOTES);
echo count($t3) >= 250 ? '1' : '0', "\n";
$euro = chr(0xe2) . chr(0x82) . chr(0xac);
echo $t3[$euro], "\n";
--EXPECT--
&lt;
1
1
&euro;
