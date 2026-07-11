<?php
declare(strict_types=1);

class C {
    public string $name = 'hi';

    public string $prop {
        get => strtoupper($this->name ?? '');
        set => $this->name = strtolower($value);
    }
}

$o = new C();
echo $o->prop, "\n";
