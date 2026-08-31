<?php
// Child may widen inherited property visibility (#25661, zend_inheritance.c).
class ProtParent25661 {
    protected string $p = 'prot';
    public function g(): string { return $this->p; }
}
class PubChild25661 extends ProtParent25661 {
    public string $p = 'pub';
    public function h(): string { return $this->p; }
}
$o = new PubChild25661();
echo $o->g(), ',', $o->h(), "\n";
