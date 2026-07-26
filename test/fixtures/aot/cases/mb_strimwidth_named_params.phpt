--TEST--
AOT: mb_strimwidth() named string:/trim_marker: arguments (#23351)
--FILE--
<?php
echo mb_strimwidth(string: 'hello', start: 0, width: 3, trim_marker: '..'), "\n";
--EXPECT--
h..
