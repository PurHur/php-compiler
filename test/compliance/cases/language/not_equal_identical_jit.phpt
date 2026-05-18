--TEST--
Not-equal and not-identical operators (JIT native types)
--FILE--
<?php
function b(bool $v): string {
    return $v ? '1' : '0';
}

echo b(0 !== 1);
echo b(1 !== 1);
echo b(0 != 1);
echo b(1 != 1);

$a = 5;
echo b($a !== 10);
echo b($a != 10);

$flag = true;
echo b($flag !== false);
echo b($flag != false);
--EXPECT--
10110111
