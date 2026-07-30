<?php
// AOT probe #25555 — subclass compare LSP + heap order (no Reflection)
class H extends SplMinHeap
{
    protected function compare(mixed $value1, mixed $value2): int
    {
        return $value1 <=> $value2;
    }
}
$h = new H();
$h->insert(2);
$h->insert(1);
$out = [];
foreach ($h as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
