<?php

declare(strict_types=1);

/**
 * Regression #10009: after `new`, settype() must still bind &$var (not Error).
 * Root cause: leftover Frame::$builtinCalleeQualifiedMethod = Class::__construct
 * made BuiltinByRefParams miss settype's by-ref arg0.
 */

$u = new stdClass();
$a = 1;
settype($a, 'string');
echo gettype($a), ':', $a, "\n";

class C
{
    public int $x = 1;

    private int $y = 2;
}

$c = new C();
settype($c, 'array');
echo isset($c["\0C\0y"]) ? 'mangled' : 'missing', ':', $c['x'], "\n";
