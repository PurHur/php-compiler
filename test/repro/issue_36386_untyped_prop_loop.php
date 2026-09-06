<?php

declare(strict_types=1);

/**
 * #36386 — untyped property write/inc in a long loop must not stack-overflow.
 *
 * Mid-block alloca on FETCH_OBJ_W allocated one __value__ per iteration; ~550k
 * writes SEGV'd. Typed int props (native int64*) were unaffected.
 *
 * php-src: Zend/zend_object_handlers.c zend_std_write_property /
 * Zend/zend_execute.c ZEND_ASSIGN_OBJ.
 */

class C
{
    public $x = 0;
}

$o = new C();
for ($i = 0; $i < 1000000; ++$i) {
    $o->x = $o->x + 1;
}
echo $o->x, "\n";

$o2 = new C();
for ($i = 0; $i < 1000000; ++$i) {
    $o2->x++;
}
echo $o2->x, "\n";
