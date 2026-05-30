--TEST--
$GLOBALS table shares storage with global import (issue #3413)
--FILE--
<?php
$GLOBALS['gp'] = 42;
function gf(): int {
    global $gp;
    return $gp;
}
echo gf();
echo "\n";
--EXPECT--
42
