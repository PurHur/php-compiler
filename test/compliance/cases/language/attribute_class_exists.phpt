--TEST--
Language: class_exists('Attribute') and TARGET_* constants (#5142)
--FILE--
<?php
echo (int) class_exists('Attribute'), "\n";
echo defined('Attribute::TARGET_CLASS') ? 'yes' : 'no', "\n";
echo Attribute::TARGET_CLASS, "\n";
echo defined('Attribute::IS_REPEATABLE') ? 'yes' : 'no', "\n";

#[Attribute]
class DemoAttr {}

echo (int) class_exists('Attribute'), "\n";
echo defined('Attribute::TARGET_CLASS') ? 'yes' : 'no', "\n";
--EXPECT--
1
yes
1
yes
1
yes
