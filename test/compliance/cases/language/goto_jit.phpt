--TEST--
goto label within function scope (JIT)
--FILE--
<?php
$i = 0;
start:
$i++;
if ($i < 3) {
    goto start;
}
echo $i, "\n";
--EXPECT--
3
