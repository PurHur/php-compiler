<?php

declare(strict_types=1);

echo function_exists('strripos') ? "exists\n" : "missing\n";
echo strripos('abcABC', 'a'), "\n";
echo strripos('abcABC', 'A'), "\n";
echo strripos('Hello', 'LL'), "\n";
echo strripos('abcabc', 'abc'), "\n";
echo strripos('hello', 'x') == false ? "notfound\n" : "found\n";
