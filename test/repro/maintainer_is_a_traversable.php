<?php
function gen(): Generator {
    yield 1;
}
$gen = gen();
echo is_a($gen, Traversable::class) ? "yes\n" : "no\n";
echo is_a($gen, Iterator::class) ? "yes\n" : "no\n";
