<?php
error_reporting(E_ALL);
class M {
    private $d = ['n' => 1];
    public function __get($k) { return $this->d[$k]; }
    public function __set($k, $v) { $this->d[$k] = $v; }
}
$m = new M();
--$m->n;
echo 'dec=', $m->n, "\n";
