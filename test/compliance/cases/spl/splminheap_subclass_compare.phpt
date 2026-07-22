--TEST--
SplMinHeap/SplMaxHeap subclass compare() override (#21977)
--FILE--
<?php
class H extends SplMinHeap
{
    protected function compare($a, $b): int
    {
        return $a <=> $b;
    }
}

$h = new H();
$h->insert(5);
$h->insert(1);
$h->insert(3);
$seq = [];
while (!$h->isEmpty()) {
    $seq[] = $h->extract();
}
echo json_encode($seq), "\n";

$min = new SplMinHeap();
$min->insert(5);
$min->insert(1);
$min->insert(3);
$seqMin = [];
while (!$min->isEmpty()) {
    $seqMin[] = $min->extract();
}
echo json_encode($seqMin), "\n";

$max = new SplMaxHeap();
$max->insert(5);
$max->insert(1);
$max->insert(3);
$seqMax = [];
while (!$max->isEmpty()) {
    $seqMax[] = $max->extract();
}
echo json_encode($seqMax), "\n";

class M extends SplMaxHeap
{
    protected function compare($a, $b): int
    {
        return $b <=> $a;
    }
}

$m = new M();
$m->insert(5);
$m->insert(1);
$m->insert(3);
$seqM = [];
while (!$m->isEmpty()) {
    $seqM[] = $m->extract();
}
echo json_encode($seqM), "\n";

// Subclass that does not override compare keeps parent ordering.
class BareMin extends SplMinHeap
{
}

$b = new BareMin();
$b->insert(5);
$b->insert(1);
$b->insert(3);
$seqB = [];
while (!$b->isEmpty()) {
    $seqB[] = $b->extract();
}
echo json_encode($seqB), "\n";
?>
--EXPECT--
[5,3,1]
[1,3,5]
[5,3,1]
[1,3,5]
[1,3,5]
