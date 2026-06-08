<?php

class Box
{
    public function __construct(private string $name)
    {
        echo $name, "\n";
    }
}

new Box('ok');
