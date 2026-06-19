--TEST--
Language: static typed enum property default preserves case singleton (#9975, re-#9747, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

enum G: string { case X = 'x'; }

class C {
    public static G $g = G::X;
}

echo C::$g->name, "\n";
--EXPECT--
X
