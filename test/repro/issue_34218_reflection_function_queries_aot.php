<?php
/**
 * #34218 — ReflectionFunction getNumberOfParameters / isUserDefined / isInternal under thin AOT.
 *
 * Expect (Zend):
 *   n=1
 *   user=1
 *   internal=0
 *   strlen_n=1
 *   strlen_user=0
 *   strlen_internal=1
 */
function foo(int $a = 1): string
{
    return 'x';
}

$r = new ReflectionFunction('foo');
echo 'n=', $r->getNumberOfParameters(), "\n";
echo 'user=', ($r->isUserDefined() ? '1' : '0'), "\n";
echo 'internal=', ($r->isInternal() ? '1' : '0'), "\n";

$s = new ReflectionFunction('strlen');
echo 'strlen_n=', $s->getNumberOfParameters(), "\n";
echo 'strlen_user=', ($s->isUserDefined() ? '1' : '0'), "\n";
echo 'strlen_internal=', ($s->isInternal() ? '1' : '0'), "\n";
