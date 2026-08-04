<?php
// Issue #27583 — NoRewindIterator foreach under AOT (second pass empty).
$it = new NoRewindIterator(new ArrayIterator([1, 2, 3]));
foreach ($it as $v) {
    echo "a$v";
}
foreach ($it as $v) {
    echo "b$v";
}
echo "\n";
