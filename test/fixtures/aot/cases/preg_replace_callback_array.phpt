--TEST--
AOT: preg_replace_callback_array() closure pattern map (issue #3568)
--FILE--
<?php
$out = preg_replace_callback_array(
    ['/\d+/' => fn(array $m): string => '[' . $m[0] . ']'],
    'a1b2'
);
echo $out, "\n";
--EXPECT--
a[1]b[2]
--EXPECT_EXIT--
0
