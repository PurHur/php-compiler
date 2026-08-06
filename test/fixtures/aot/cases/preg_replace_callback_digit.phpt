--TEST--
AOT preg_replace_callback() digit class pattern (issue #26820)
--FILE--
<?php
function double_digit(array $m): string {
    return (string) ((int) $m[0] * 2);
}
echo preg_replace_callback('/\d/', 'double_digit', 'a1b2'), "\n";
--EXPECT--
a2b4
