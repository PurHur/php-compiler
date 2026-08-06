<?php
/**
 * #28039 — Closure in function-local static must stay callable across calls.
 * Zend/zend_closures.c + Zend/zend_execute.c BIND_STATIC.
 */
function f(): int
{
    static $c = null;
    if ($c === null) {
        $c = function ($n) {
            return $n * 2;
        };
    }

    return $c(3);
}

function g(): int
{
    static $c = null;
    $c ??= function ($n) {
        return $n * 2;
    };

    return $c(3);
}

$outer = function (): int {
    static $c = null;
    if ($c === null) {
        $c = function ($n) {
            return $n * 2;
        };
    }

    return $c(3);
};

echo f(), "\n", f(), "\n";
echo g(), "\n", g(), "\n";
echo $outer(), "\n", $outer(), "\n";
function h(): string
{
    static $c = null;
    if ($c === null) {
        $c = function ($n) {
            return $n * 2;
        };
    }

    return is_callable($c) ? 'Y' : 'N';
}
echo h(), "\n", h(), "\n";
