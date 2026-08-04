--TEST--
AOT: is_callable() array [object,method] / [Class,static] (#27173)
--FILE--
<?php
echo is_callable('strlen') ? 'str=y' : 'str=n', "\n";
echo is_callable([new DateTime(), 'format']) ? 'arr=y' : 'arr=n', "\n";
echo is_callable(['DateTime', 'createFromFormat']) ? 'static=y' : 'static=n', "\n";
?>
--EXPECT--
str=y
arr=y
static=y
