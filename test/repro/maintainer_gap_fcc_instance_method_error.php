<?php

class C
{
    public function m(): void
    {
    }
}

$o = new C();
try {
    ($o->m)(new C());
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
