--TEST--
get_html_translation_table() ISO-8859-1 encoding parity (issue #4459, ext/standard/html.c)
--FILE--
<?php
declare(strict_types=1);

$t = get_html_translation_table(HTML_SPECIALCHARS, ENT_QUOTES, 'ISO-8859-1');
echo count($t), "\n";
echo $t['<'], "\n";

$t2 = get_html_translation_table(HTML_ENTITIES, ENT_QUOTES, 'ISO-8859-1');
echo count($t2), "\n";
echo $t2[chr(0xe9)], "\n";
echo $t2[chr(0xa0)], "\n";
--EXPECT--
5
&lt;
101
&eacute;
&nbsp;
