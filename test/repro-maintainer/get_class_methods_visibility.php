<?php

declare(strict_types=1);

class C
{
    public function a(): void
    {
    }

    private function b(): void
    {
    }

    public static function c(): void
    {
    }
}

var_export(get_class_methods(C::class));
echo "\n";
