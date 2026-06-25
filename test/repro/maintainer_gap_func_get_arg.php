<?php

declare(strict_types=1);

function maintainer_func_get_arg_probe(int $a, int $b): void
{
    echo 'arg0=', var_export(func_get_arg(0), true), "\n";
    echo 'arg1=', var_export(func_get_arg(1), true), "\n";
}

maintainer_func_get_arg_probe(10, 20);
