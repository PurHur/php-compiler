<?php
/**
 * Repro for #23484 — __destruct must run on $obj=null / overwrite, not only unset().
 * Zend: 12[D:A]34\n[D:B]
 */
class C {
    public function __construct(private string $n) {}
    public function __destruct() { echo "[D:{$this->n}]"; }
}
echo '1';
$a = new C('A');
echo '2';
$a = null;
echo '3';
$b = new C('B');
echo '4';
echo "\n";
