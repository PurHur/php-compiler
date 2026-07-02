<?php

class P
{
    public function m(): void
    {
    }
}

class C extends P
{
}

try {
    (C::m)(new C());
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
