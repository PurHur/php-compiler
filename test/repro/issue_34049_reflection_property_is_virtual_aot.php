<?php
/**
 * #34049 — ReflectionProperty::isVirtual under thin AOT (peer of #34047/#34043).
 * php-src: ext/reflection/php_reflection.c zim_ReflectionProperty_isVirtual
 * Requires PROFILE≥8.4 for property hooks.
 */
class Plain {
    public int $x = 1;
}
class Hooked {
    public string $title {
        get => 'hook';
    }
}
echo 'plain=', (new ReflectionProperty(Plain::class, 'x'))->isVirtual() ? '1' : '0', "\n";
echo 'hook=', (new ReflectionProperty(Hooked::class, 'title'))->isVirtual() ? '1' : '0', "\n";
