<?php

class B
{
    public function make(): object
    {
        return new class {
            public function t(): string
            {
                return 'anon';
            }
        };
    }
}

echo (new B())->make()->t(), "\n";
