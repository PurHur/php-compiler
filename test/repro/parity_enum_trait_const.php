<?php
declare(strict_types=1);

trait T {
    public const X = 1;
}

enum E {
    use T;
}

echo E::X, "\n";
