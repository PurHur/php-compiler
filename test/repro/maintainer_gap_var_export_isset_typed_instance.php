<?php

declare(strict_types=1);

class T
{
    public int $i;
}

$t = new T();
echo var_export(isset($t->i), true), "\n";
echo var_export(empty($t->i), true), "\n";

unset($t->i);
echo var_export(isset($t->i), true), "\n";
