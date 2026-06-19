<?php
declare(strict_types=1);

class Base {
    public int $x = 1;
}

class Child extends Base {
    #[\Override]
    public int $x = 2;
}

echo (new Child())->x, "\n";
