--TEST--
mbstring mb_convert_encoding() HTML-ENTITIES numeric for non-ASCII (#22631)
--FILE--
<?php
echo mb_convert_encoding('あ', 'HTML-ENTITIES', 'UTF-8'), "\n";
echo mb_convert_encoding('über', 'HTML-ENTITIES', 'UTF-8'), "\n";
echo mb_convert_encoding('<>&', 'HTML-ENTITIES', 'UTF-8'), "\n";
echo mb_convert_encoding('&#12354;', 'UTF-8', 'HTML-ENTITIES'), "\n";
--EXPECT--
&#12354;
&uuml;ber
<>&
あ
