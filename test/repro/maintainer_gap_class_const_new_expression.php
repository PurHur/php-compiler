<?php

declare(strict_types=1);

class Holder {
    public const OBJ = new \stdClass();
}

echo get_class(Holder::OBJ), "\n";
echo Holder::OBJ === Holder::OBJ ? "1\n" : "0\n";
