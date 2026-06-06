<?php
declare(strict_types=1);

class Asym {
    public private(set) string $name = 'x';
}
$a = new Asym();
echo $a->name, "\n";
$a->name = 'y';
