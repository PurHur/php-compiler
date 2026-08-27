<?php
// #35535 — return through empty finally must compile under AOT (re-#32371).
function ret_try(): int
{
    try {
        return 1;
    } finally {
    }
}

function ret_catch(): string
{
    try {
        throw new Exception('x');
    } catch (Exception $e) {
        return $e->getMessage();
    } finally {
    }
}

echo ret_try(), "\n";
echo ret_catch(), "\n";
