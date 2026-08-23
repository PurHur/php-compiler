<?php
/**
 * #34047 — ReflectionProperty::isFinal under thin AOT (PHP 8.4 final properties).
 * php-src: ext/reflection/php_reflection.c zim_ReflectionProperty_isFinal
 *
 * Requires PHP_COMPILER_PROFILE=8.4 (or host 8.4+) so `final` properties parse.
 */
class H
{
    public final int $z = 1;
}
class U
{
    public int $x = 1;
}
echo 'H.z=', (new ReflectionProperty(H::class, 'z'))->isFinal() ? '1' : '0', "\n";
echo 'U.x=', (new ReflectionProperty(U::class, 'x'))->isFinal() ? '1' : '0', "\n";
