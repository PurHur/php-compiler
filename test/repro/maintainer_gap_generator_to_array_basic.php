<?php
// #19131 — generator_to_array() VM/JIT/AOT on PHP 8.4 forward profile.
declare(strict_types=1);

function gen(): Generator
{
    yield 'a';
    yield 'b';
}

$result = generator_to_array(gen());
if (!is_array($result) || ['a', 'b'] !== $result) {
    echo 'fail: ', var_export($result, true), "\n";
    exit(1);
}

echo "ok\n";
