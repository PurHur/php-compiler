<?php
foreach (['array_walk', 'array_walk_recursive'] as $f) {
    $rf = new ReflectionFunction($f);
    echo $f,
        ' return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none',
        ' arr=', (string) $rf->getParameters()[0]->getType(),
        "\n";
}
