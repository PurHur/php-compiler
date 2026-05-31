--TEST--
AOT: anonymous class returned from method with :object return type (#3098)
--FILE--
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
--EXPECT--
anon
--EXPECT_EXIT--
0
