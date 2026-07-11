--TEST--
stdlib unserialize(serialize($obj)) __sleep roundtrip (VM, issue #12390)
--FILE--
<?php
final class SleepyBox
{
    public int $x = 7;

    public function __sleep(): array
    {
        return ['x'];
    }
}

$obj = new SleepyBox();
$restored = unserialize(serialize($obj));
echo ($restored instanceof SleepyBox) ? "ok" : "bad", "\n";
echo $restored->x, "\n";
--EXPECT--
ok
7

