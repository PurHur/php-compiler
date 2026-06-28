<?php

declare(strict_types=1);

$ok = function_exists('mb_split')
    && mb_split(',', 'a,b,c') === ['a', 'b', 'c'];

echo $ok ? "ok\n" : "fail\n";
