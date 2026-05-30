--TEST--
Promoted readonly constructor parameters reject writes after construction (#3432)
--FILE--
<?php
class U {
    public function __construct(public readonly string $id) {}
}
$u = new U('a');
try {
    $u->id = 'b';
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
Error: Cannot modify readonly property U::$id
