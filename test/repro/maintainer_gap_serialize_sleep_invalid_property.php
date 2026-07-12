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
