--TEST--
AOT: function-static string $s[i]='Z' stays a string (#32814)
--FILE--
<?php
function f(): void {
    static $s = 'abc';
    $s[1] = 'Z';
    echo $s, "\n";
}
f();
f();
--EXPECT--
aZc
aZc
--EXPECT_EXIT--
0
