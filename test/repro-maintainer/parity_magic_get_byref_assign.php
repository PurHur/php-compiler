<?php

class OverloadedMagic {
    private array $d = ['k' => 1];

    public function &__get(string $name)
    {
        return $this->d[$name];
    }
}

$om = new OverloadedMagic();
$om->k = 2;
echo $om->k, "\n";
