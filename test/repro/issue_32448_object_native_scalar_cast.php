<?php
/**
 * #32448 — AOT (object) native scalars are stdClass->{scalar}
 * (php-src Zend/zend_operators.c convert_to_object).
 *
 * Avoid var_dump of objects: thin AOT has no Runtime->vm object dumper (#23540).
 * Echo bools as 1/empty (Zend echo of true/false); do not ternary a boxed
 * bool property (that path fails module verify independently).
 */
$i = (object) 1;
echo get_class($i), ':', $i->scalar, "\n";
$s = (object) 'hi';
echo $s->scalar, "\n";
$t = (object) true;
echo $t->scalar, "\n";
$f = (object) false;
echo '[', $f->scalar, "]\n";
$d = (object) 1.5;
echo $d->scalar, "\n";
$n = (object) null;
echo isset($n->scalar) ? "has\n" : "empty\n";
