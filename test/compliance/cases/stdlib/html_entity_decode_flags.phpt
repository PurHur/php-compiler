--TEST--
stdlib html_entity_decode() ENT_* flags (#2472)
--FILE--
<?php
echo html_entity_decode('a&quot;b&#039;c', 0), "\n";
echo html_entity_decode('a&quot;b&#039;c', 2), "\n";
echo html_entity_decode('a&quot;b&#039;c', 3), "\n";
echo html_entity_decode('&#39;x', 3), "\n";
--EXPECT--
a&quot;b&#039;c
a"b'c
a"b'c
'x
