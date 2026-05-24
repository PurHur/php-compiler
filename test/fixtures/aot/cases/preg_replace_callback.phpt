--TEST--
AOT preg_replace_callback() compile-time string callback (issue #1177)
--FILE--
<?php
function upper_matches(array $m): string {
    return strtoupper($m[0]);
}
echo preg_replace_callback('/[a-z]+/', 'upper_matches', 'foo BAR baz'), "\n";
--EXPECT--
FOO BAR BAZ
