<?php
/** Repro #24250 — unset($obj->arr[$k]) must mutate instance array properties (Zend ZEND_UNSET_DIM). */
class C {
    public array $t = ['x' => 1, 'y' => 2];
    private array $pt = ['a' => 1];
    public $u = ['p' => 1, 'q' => 2];
    private $pu = ['b' => 1];

    public function wipe(): void
    {
        unset($this->t['x'], $this->pt['a'], $this->u['p'], $this->pu['b']);
    }

    public function dumpPt(): array
    {
        return $this->pt;
    }

    public function dumpPu(): array
    {
        return $this->pu;
    }
}

$c = new C();
$c->wipe();
unset($c->t['y']);
unset($c->u['q']);
echo 't=';
var_export($c->t);
echo "\n";
echo 'pt=';
var_export($c->dumpPt());
echo "\n";
echo 'u=';
var_export($c->u);
echo "\n";
echo 'pu=';
var_export($c->dumpPu());
echo "\n";
