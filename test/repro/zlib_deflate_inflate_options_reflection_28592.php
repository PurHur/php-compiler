<?php
/** Repro #28592 — deflate_init/inflate_init $options object|array. */
foreach (['deflate_init', 'inflate_init'] as $f) {
    $p = (new ReflectionFunction($f))->getParameters()[1];
    echo $f, ' options=', (string) $p->getType(), "\n";
}
