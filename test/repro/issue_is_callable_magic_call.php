<?php

declare(strict_types=1);

class MagicCallInstance
{
    public function __call(string $name, array $args): void
    {
    }
}

class MagicCallStatic
{
    public static function __callStatic(string $name, array $args): void
    {
    }
}

$o = new MagicCallInstance();
var_export(is_callable([$o, 'missing']));
echo "\n";
var_export(is_callable([MagicCallStatic::class, 'missing']));
echo "\n";
