<?php
// Public override
class PubA { public $p = 'a'; }
class PubB extends PubA { public $p = 'b'; }
echo (new PubB)->p, ',';

// Protected override
class ProtA { protected $p = 'a'; public function g() { return $this->p; } }
class ProtB extends ProtA { protected $p = 'b'; }
echo (new ProtB)->g(), ',';

// Private shadow (coexist)
class PrivA {
    private $p = 'a';
    public function g() { return $this->p; }
}
class PrivB extends PrivA {
    private $p = 'b';
    public function h() { return $this->p; }
}
$o = new PrivB;
echo $o->g(), ',', $o->h(), "\n";
