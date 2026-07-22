<?php
// Repro #21977 — SplMinHeap/SplMaxHeap subclass compare()
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
echo 'subclass: ', json_encode($seq), "\n";

$h2 = new SplMinHeap();
$h2->insert(5);
$h2->insert(1);
$h2->insert(3);
$seq2 = [];
while (!$h2->isEmpty()) {
    $seq2[] = $h2->extract();
}
echo 'min: ', json_encode($seq2), "\n";

$h3 = new SplMaxHeap();
$h3->insert(5);
$h3->insert(1);
$h3->insert(3);
$seq3 = [];
while (!$h3->isEmpty()) {
    $seq3[] = $h3->extract();
}
echo 'max: ', json_encode($seq3), "\n";

class M extends SplMaxHeap
{
    protected function compare($a, $b): int
    {
        return $b <=> $a;
    }
}
$h4 = new M();
$h4->insert(5);
$h4->insert(1);
$h4->insert(3);
$seq4 = [];
while (!$h4->isEmpty()) {
    $seq4[] = $h4->extract();
}
echo 'maxsub: ', json_encode($seq4), "\n";
