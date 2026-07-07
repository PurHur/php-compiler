<?php

declare(strict_types=1);

class LazyC
{
    public lazy int $buffer {
        get {
            echo "init\n";
            return 42;
        }
    }
}

$c = new LazyC();
var_dump($c->buffer);
var_dump($c->buffer);
