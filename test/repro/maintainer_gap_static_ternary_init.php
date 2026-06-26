<?php

function f(): void
{
    static $x = true ? 1 : 2;
    echo 'x='.$x."\n";
}

f();
f();
echo "done\n";
