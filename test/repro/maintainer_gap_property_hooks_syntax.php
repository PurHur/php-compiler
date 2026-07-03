<?php
declare(strict_types=1);

class C {
    public string $name {
        get => strtoupper($this->name);
        set => $this->name = $value;
    }
}

$c = new C();
$c->name = 'Hello';
echo $c->name, "\n";
