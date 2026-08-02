--TEST--
AOT: promoted readonly write throws catchable Error (#26854)
--FILE--
<?php
class R { public function __construct(public readonly int $x) {} }
$r = new R(3);
echo $r->x, "\n";
try {
    $r->x = 4;
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
3
Error
