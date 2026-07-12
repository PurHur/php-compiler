<?php

declare(strict_types=1);

$c = Closure::fromCallable('strlen');
$b = $c->bindTo(new stdClass(), stdClass::class);
var_dump($b);
