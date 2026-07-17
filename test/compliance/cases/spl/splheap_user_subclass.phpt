--TEST--
SplHeap concrete subclass construct + compare() (#19891)
--FILE--
<?php
class H extends SplHeap
{
    protected function compare(mixed $a, mixed $b): int
    {
        return $a <=> $b;
    }
}

$h = new H();
$h->insert(1);
$h->insert(3);
$h->insert(2);
echo $h->extract(), ',', $h->extract(), ',', $h->extract(), "\n";

class MinH extends SplHeap
{
    protected function compare(mixed $a, mixed $b): int
    {
        return $b <=> $a;
    }
}

$m = new MinH();
$m->insert(1);
$m->insert(3);
$m->insert(2);
echo $m->extract(), ',', $m->extract(), ',', $m->extract(), "\n";

try {
    new SplHeap();
    echo "direct-ok\n";
} catch (Error $e) {
    echo "abstract\n";
}

echo (new ReflectionClass(H::class))->hasMethod('__construct') ? "ctor-y\n" : "ctor-n\n";
?>
--EXPECT--
3,2,1
1,2,3
abstract
ctor-n
