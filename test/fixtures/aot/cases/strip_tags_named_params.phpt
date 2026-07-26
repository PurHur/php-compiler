--TEST--
AOT: strip_tags() named string:/allowed_tags: arguments (#23217)
--FILE--
<?php
echo strip_tags(string: '<b>x</b>', allowed_tags: '<b>'), "\n";
echo strip_tags(string: '<b>x</b>'), "\n";
--EXPECT--
<b>x</b>
x
