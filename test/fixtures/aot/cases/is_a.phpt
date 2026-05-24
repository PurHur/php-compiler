--TEST--
AOT is_a() object instance (issue #1220)
--FILE--
<?php
class Widget {}
$w = new Widget();
echo is_a($w, 'Widget') ? '1' : '0';
echo is_a($w, 'Other') ? '1' : '0';
--EXPECT--
10
