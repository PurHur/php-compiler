<?php
declare(strict_types=1);

// #23509 — __sleep must emit NUL-class / NUL-* mangled keys like plain serialize (ext/standard/var.c).

class SleepMangleA
{
    private $x = 1;
    protected $p = 2;
    public $y = 3;

    public function __sleep(): array
    {
        return ['x', 'p', 'y'];
    }
}

class SleepParentPriv
{
    private $p = 1;
}

class SleepChildPriv extends SleepParentPriv
{
    public function __sleep(): array
    {
        return ['p'];
    }
}

echo bin2hex(serialize(new SleepMangleA())), "\n";
echo serialize(new SleepChildPriv()), "\n";
