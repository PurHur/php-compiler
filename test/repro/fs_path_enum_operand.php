<?php

enum PathEnum: string
{
    case A = 'x';
}

enum LocalUnitEnum
{
    case A;
}

$fns = [
    'file_get_contents',
    'file_exists',
    'is_file',
    'is_dir',
    'is_link',
    'is_readable',
    'is_writable',
    'is_executable',
    'unlink',
    'mkdir',
    'rmdir',
];

foreach ($fns as $fn) {
    if (!function_exists($fn)) {
        echo "{$fn} missing\n";
        continue;
    }
    foreach (['backed' => PathEnum::A, 'unit' => LocalUnitEnum::A] as $label => $operand) {
        try {
            $fn($operand);
            echo "{$fn} {$label} ok\n";
        } catch (TypeError $e) {
            echo "{$fn} {$label} TypeError\n";
        } catch (LogicException $e) {
            echo "{$fn} {$label} LogicException\n";
        }
    }
}
