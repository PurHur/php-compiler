<?php
function gen(): iterable {
    yield 1;
}
foreach (gen() as $v) {
    echo "y:$v\n";
}
echo "ok\n";

function genObj(): object {
    yield 2;
}
foreach (genObj() as $v) {
    echo "y:$v\n";
}
echo "ok\n";
