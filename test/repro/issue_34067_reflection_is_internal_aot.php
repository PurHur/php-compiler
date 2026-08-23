<?php
/**
 * #34067 — ReflectionClass isInternal / isUserDefined / isReadOnly under thin AOT.
 *
 * Expect (Zend):
 *   Int=1,0
 *   User=0,1
 *   Ro=1,0
 */
class U
{
}

readonly class R
{
}

echo 'Int=',
    ((new ReflectionClass('stdClass'))->isInternal() ? '1' : '0'), ',',
    ((new ReflectionClass('U'))->isInternal() ? '1' : '0'),
    "\n";
echo 'User=',
    ((new ReflectionClass('stdClass'))->isUserDefined() ? '1' : '0'), ',',
    ((new ReflectionClass('U'))->isUserDefined() ? '1' : '0'),
    "\n";
echo 'Ro=',
    ((new ReflectionClass('R'))->isReadOnly() ? '1' : '0'), ',',
    ((new ReflectionClass('U'))->isReadOnly() ? '1' : '0'),
    "\n";
