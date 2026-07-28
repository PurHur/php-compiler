<?php
// #24008: constructor property promotion must store the ctor argument under AOT.
// Classic `$this->s = $s` already matched Zend; promotion previously left the native
// int slot holding a __value__* box pointer (read back as 1025 / 1050625).
class Sq { public function __construct(public int $s) {} public function area(): int { return $this->s * $this->s; } }
$q = new Sq(4);
echo $q->s, "\n";
echo $q->area(), "\n";
