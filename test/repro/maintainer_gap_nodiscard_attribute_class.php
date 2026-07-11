<?php

declare(strict_types=1);

if (!class_exists('NoDiscard', false)) {
    echo "fail: NoDiscard builtin attribute class missing on PHP_COMPILER_PROFILE=8.4\n";
    exit(1);
}

if (!(new ReflectionClass('NoDiscard'))->isInternal()) {
    echo "fail: NoDiscard must be internal\n";
    exit(1);
}

#[NoDiscard]
function f(): int
{
    return 1;
}

echo f(), "\n";
