<?php

declare(strict_types=1);

foreach (['Iterator', 'Traversable', 'ArrayObject', 'Generator'] as $class) {
    echo $class, '=', (new ReflectionClass($class))->isIterateable() ? 'true' : 'false', "\n";
}

$ok = !(new ReflectionClass('Iterator'))->isIterateable()
    && !(new ReflectionClass('Traversable'))->isIterateable()
    && (new ReflectionClass('ArrayObject'))->isIterateable()
    && (new ReflectionClass('Generator'))->isIterateable();

exit($ok ? 0 : 1);
