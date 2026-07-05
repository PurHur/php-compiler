<?php
declare(strict_types=1);

$o = new readonly class {
    public int $x = 1;
};
echo $o->x, "\n";
