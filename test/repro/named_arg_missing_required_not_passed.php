<?php
/**
 * Repro #29095 — named/unpack omission of a required param → Argument #N ($name) not passed
 * (not positional "Too few arguments"), matching Zend/zend_execute.c.
 */
function f($a, $b)
{
    return "$a-$b";
}

try {
    echo f(b: 2), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    echo f(...['b' => 2]), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

class C
{
    public function m($a, $b)
    {
    }

    public static function s($a, $b)
    {
    }

    public function __invoke($a, $b)
    {
    }
}

try {
    (new C())->m(b: 2);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

try {
    C::s(b: 2);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

try {
    (new C())(b: 2);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

$c = function ($a, $b) {
};

try {
    $c(b: 2);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

// Positional too-few must stay Zend's Too few wording.
try {
    echo f(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
