<?php

declare(strict_types=1);

/**
 * Discarded class_parents / class_implements / class_uses must not change
 * observable hierarchy maps (#36386).
 *
 * Top-level only: AOT class_parents/implements/uses inside a user function
 * segfaults on master (pre-existing).
 *
 * php-src: ext/standard/class.c, basic_functions.c, spl_functions.c
 */

interface I36386Ch {}
trait T36386Ch {}
class Base36386Ch {}
class Child36386Ch extends Base36386Ch implements I36386Ch
{
    use T36386Ch;
}

$o = new Child36386Ch();
class_parents($o);
class_implements($o);
class_uses($o);
class_parents($o, false);
class_implements($o, true);
class_uses($o, false);

$p = class_parents($o);
$i = class_implements($o);
$u = class_uses($o);

echo (isset($p['Base36386Ch']) ? '1' : '0')
    . (isset($i['I36386Ch']) ? '1' : '0')
    . (isset($u['T36386Ch']) ? '1' : '0'), "\n";
