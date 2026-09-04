<?php

declare(strict_types=1);

/**
 * Discarded get_object_vars / get_mangled_object_vars / get_class_methods must
 * not change observable property maps (#36386).
 *
 * get_class_methods is discarded-only here: live AOT currently returns NULL
 * (pre-existing; not this slice). Property helpers match Zend.
 *
 * php-src: Zend/zend_builtin_functions.c, ext/standard/var.c
 */

class Node36386Ovm
{
    public int $n = 7;
    private int $hidden = 9;

    public function m(): void
    {
    }
}

$o = new Node36386Ovm();
get_object_vars($o);
get_mangled_object_vars($o);
get_class_methods($o);

$v = get_object_vars($o);
$m = get_mangled_object_vars($o);

echo (isset($v['n']) && 7 === $v['n'] ? '1' : '0')
    . (isset($m["\0Node36386Ovm\0hidden"]) && 9 === $m["\0Node36386Ovm\0hidden"] ? '1' : '0'), "\n";
