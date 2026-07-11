<?php

declare(strict_types=1);

class First {
    public private(set) int $x = 1;
}

class Second {
    public (private(set)) int $y = 2;
}

echo "parsed\n";
