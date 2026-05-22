--TEST--
AOT: void function ignores return value operand (issue #58 / #55)
--FILE--
<?php
declare(strict_types=1);
function greet(): void
{
    $unused = ['k' => 'v'];
    return;
}
greet();
echo "ok\n";
--EXPECT--
ok
