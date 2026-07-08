<?php

declare(strict_types=1);

class Box {
    private string $stored = 'init';

    public string $x {
        get => $this->stored;
        set($v) => $this->stored = strtoupper($v);
    }
}

$box = new Box();
$box->x = 'hello';
echo $box->x, "\n";
