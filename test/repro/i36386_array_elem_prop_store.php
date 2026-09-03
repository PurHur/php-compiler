<?php

declare(strict_types=1);

/**
 * Untyped / array-elem object property stores must mutate the shared object (#36386).
 *
 * php-src: Zend/zend_object_handlers.c zend_get_property_offset (Z_OBJCE_P).
 */

class Body {
    public float $x;

    public function __construct(float $x)
    {
        $this->x = $x;
    }
}

function bump_untyped($o): void
{
    $o->x = $o->x + 1.0;
}

function bump_array(array $a): void
{
    $a[0]->x = $a[0]->x + 1.0;
}

function bump_typed(Body $o): void
{
    $o->x = 9.0;
}

$b = new Body(1.0);
bump_untyped($b);
echo 'u=', $b->x, "\n";

$bodies = [new Body(1.0), new Body(2.0)];
bump_array($bodies);
echo 'a=', $bodies[0]->x, ':', $bodies[1]->x, "\n";

$t = new Body(1.0);
bump_typed($t);
echo 't=', $t->x, "\n";
