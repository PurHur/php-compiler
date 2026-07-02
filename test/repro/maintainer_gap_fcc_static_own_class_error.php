<?php

class C
{
    public static function m(): void
    {
    }
}

try {
    (C::m)(new C());
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

