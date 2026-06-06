<?php

function f(bool $b): void
{
    throw $b ? new Exception('a') : new Error('b');
}

try {
    f(false);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

try {
    f(true);
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
