--TEST--
ext/mbstring: mb_list_encodings() returns UTF-8 table (php-src ext/mbstring/mbstring.c, #15448)
--FILE--
<?php
$encodings = mb_list_encodings();
echo is_array($encodings) ? "array\n" : "not_array\n";
echo in_array('UTF-8', $encodings, true) ? "has_utf8\n" : "no_utf8\n";
echo count($encodings) > 0 ? "non_empty\n" : "empty\n";
?>
--EXPECT--
array
has_utf8
non_empty
