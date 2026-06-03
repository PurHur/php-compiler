<?php
function g(): int {
    try {
        return 1;
    } finally {
        throw new Exception('f');
    }
}
try {
    var_dump(g());
} catch (Throwable $e) {
    echo "caught: ".$e->getMessage()."\n";
}
