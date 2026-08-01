<?php
// #26467 — Zend rejects scalar generator return types at compile time.
function gen(): int {
    yield 1;
    return 2;
}
foreach (gen() as $v) {
    echo "y:$v\n";
}
echo "survived\n";
