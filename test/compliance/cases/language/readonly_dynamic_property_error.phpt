--TEST--
Language: readonly class dynamic property Error is catchable (#4799, zend_objects.c)
--FILE--
<?php
readonly class R {
    public int $x;
    public function __construct(int $x) {
        $this->x = $x;
    }
}
$r = new R(1);
try {
    $r->y = 2;
} catch (Throwable $e) {
    echo 'caught ', get_class($e), PHP_EOL;
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
caught Error
Cannot create dynamic property R::$y
