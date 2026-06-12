<?php

class C
{
    public function f(): void
    {
        echo get_class(), "\n";
    }
}

(new C())->f();
