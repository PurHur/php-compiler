<?php

declare(strict_types=1);

trait T7417
{
    public static int $a = 1;
    public static string $b = 'x';
}

class C7417
{
    use T7417;
    public static int $c = 2;
}

var_export(get_class_vars(C7417::class));
echo "\n";
