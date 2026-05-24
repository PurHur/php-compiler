--TEST--
Static property fetch and assignment (issue #1225)
--FILE--
<?php
class Counter {
    public static int $n = 0;
}
Counter::$n = 5;
echo Counter::$n, "\n";
class Worker {
    public static string $tag = 'init';
    public function relabel(): void {
        self::$tag = 'done';
    }
}
$w = new Worker();
$w->relabel();
echo Worker::$tag, "\n";
--EXPECT--
5
done
