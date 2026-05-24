--TEST--
stdlib is_a() object and allow_string (issue #1220)
--FILE--
<?php
class Widget {}
$w = new Widget();
echo is_a($w, 'Widget') ? '1' : '0';
echo is_a($w, 'Other') ? '1' : '0';
echo is_a('Widget', 'Widget', true) ? '1' : '0';
echo is_a('Widget', 'Other', true) ? '1' : '0';
echo is_a('Widget', 'Widget') ? '1' : '0';
echo "\n";
--EXPECT--
10100
