--TEST--
JIT: preg_replace_callback() compile-time string callback (issue #1177)
--FILE--
<?php
function upper_matches(array $m): string {
    return strtoupper($m[0]);
}
echo preg_replace_callback('/[a-z]+/', 'upper_matches', 'foo BAR baz'), "\n";
$bad = preg_replace_callback('(bad[pattern', 'upper_matches', 'hello');
echo $bad === false ? 'false' : 'bad', "\n";
--EXPECT--
FOO BAR BAZ
false
