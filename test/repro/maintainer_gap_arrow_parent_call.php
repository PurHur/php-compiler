<?php

class Base
{
    public function value(): int
    {
        return 1;
    }
}

class Child extends Base
{
    public function value(): int
    {
        return 2;
    }

    public function run(): int
    {
        $fn = fn (): int => parent::value();

        return $fn->call($this);
    }
}

echo (new Child())->run(), "\n";
