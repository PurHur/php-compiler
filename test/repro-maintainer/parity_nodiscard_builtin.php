<?php

declare(strict_types=1);

var_dump(class_exists('NoDiscard'));

#[NoDiscard]
function f(): int {
    return 1;
}

f();
(void) f();
echo "ok\n";
