<?php

declare(strict_types=1);

$f = function (): string {
    return __DIR__;
};
echo $f(), "\n";

$g = fn (): string => __FILE__;
echo $g(), "\n";

$h = function (): int {
    return __LINE__;
};
echo $h(), "\n";
