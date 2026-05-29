--TEST--
AOT class_implements() on class and object (issue #3099)
--FILE--
<?php
interface Tag {}
class Widget implements Tag {}
$w = new Widget();
$byClass = class_implements('Widget');
$byObject = class_implements($w);
echo isset($byClass['Tag']) ? '1' : '0';
echo isset($byObject['Tag']) ? '1' : '0';
--EXPECT--
11
