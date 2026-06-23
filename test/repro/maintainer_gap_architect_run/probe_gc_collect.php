<?php

declare(strict_types=1);

class C
{
    public function __destruct()
    {
        echo "dtor\n";
    }
}

$a = new C();
unset($a);
echo gc_collect_cycles(), "\n";
