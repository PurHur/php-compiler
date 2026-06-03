<?php
function gen(): Generator {
    yield 1;
}
$gen = gen();
echo ($gen instanceof Traversable) ? "yes\n" : "no\n";
echo ($gen instanceof Iterator) ? "yes\n" : "no\n";
