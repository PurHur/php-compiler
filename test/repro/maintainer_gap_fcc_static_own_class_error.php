<?php

class C
{
    public function m(): void
    {
    }
}

try {
    (C::m)(new C());
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
