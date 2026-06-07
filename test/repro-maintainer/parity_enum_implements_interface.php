<?php

interface Greeter
{
    public function greet(): void;
}

enum Status implements Greeter
{
    case Open;
}

echo "compiled\n";
