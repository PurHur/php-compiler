<?php

declare(strict_types=1);

$closure = function (): int {
    return 42;
};
var_dump($closure->call(new stdClass()));
