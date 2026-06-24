<?php
declare(strict_types=1);

class Box {
    private string $label = 'init';
    public string $name {
        get => strtoupper($this->label);
        set => $this->label = strtolower($value);
    }
}

$o = new Box();
$rp = new ReflectionProperty(Box::class, 'name');

if (!method_exists($rp, 'setRawValue')) {
    echo "missing setRawValue\n";
    exit(1);
}

$rp->setRawValue($o, 'RAW');
echo $o->name, "\n";
echo $rp->getRawValue($o), "\n";
