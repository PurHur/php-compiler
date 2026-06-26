<?php

function f_list(): void
{
    static $x = [1, 2][0];
    var_export($x === 1);
}

function f_assoc(): void
{
    static $x = ['a' => 1]['a'];
    var_export($x === 1);
}

function f_add(): void
{
    static $x = 1 + 2;
    var_export($x === 3);
}

f_list();
echo "\n";
f_assoc();
echo "\n";
f_add();
echo "\n";
echo "ok\n";
