--TEST--
AOT: explode() splits string into indexed list
--FILE--
<?php
$parts = explode(',', 'a,b,c');
echo $parts[0], '|', $parts[1], '|', $parts[2], "\n";
echo $parts[0], '|', explode(',', 'solo')[0], "\n";
--EXPECT--
a|b|c
a|solo
