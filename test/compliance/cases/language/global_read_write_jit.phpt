--TEST--
Global variable read/write across functions JIT (issue #100)
--FILE--
<?php
function init(): void
{
    global $x;
    $x = 1;
}

function bump(): void
{
    global $x;
    $x = $x + 1;
}

function read_x(): void
{
    global $x;
    echo (string) $x;
}

init();
bump();
read_x();
echo "\n";
--EXPECT--
2
