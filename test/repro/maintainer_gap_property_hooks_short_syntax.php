<?php
// Issue #12941 — PHP 8.4 property hooks short `{ get => / set => }` syntax (Zend/zend_compile.c).
class Box {
    public int $n {
        get => $this->n ?? 0;
        set => $this->n = $value;
    }
}
$b = new Box();
$b->n = 5;
echo $b->n, "\n";
