--TEST--
Language: readonly class may use trait with readonly properties (#26592)
--FILE--
<?php
trait T {
    public readonly int $x;
}
readonly class R {
    use T;
}
echo "COMPILED\n";
--EXPECT--
COMPILED
