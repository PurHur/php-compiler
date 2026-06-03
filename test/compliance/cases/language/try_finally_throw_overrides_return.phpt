--TEST--
Language: throw in finally overrides pending return (#5331)
--FILE--
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
--EXPECT--
caught: f
