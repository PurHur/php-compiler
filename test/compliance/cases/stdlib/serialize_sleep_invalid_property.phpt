--TEST--
stdlib serialize() __sleep() unknown property — warn + empty/partial bag (#18127, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

class SleepMissingProp
{
    public int $x = 1;

    public function __sleep(): array
    {
        return ['missing'];
    }
}

class SleepIntKey
{
    public function __sleep(): array
    {
        return [0];
    }
}

class SleepPartial
{
    public int $x = 1;

    public function __sleep(): array
    {
        return ['missing', 'x'];
    }
}

echo serialize(new SleepMissingProp()), "\n";
echo serialize(new SleepIntKey()), "\n";
echo serialize(new SleepPartial()), "\n";
?>
--EXPECT--
O:16:"SleepMissingProp":0:{}
O:11:"SleepIntKey":0:{}
O:12:"SleepPartial":1:{s:1:"x";i:1;}
