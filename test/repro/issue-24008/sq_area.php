<?php
class Sq { public function __construct(private int $s) {} public function area(): int { return $this->s * $this->s; } }
echo (new Sq(4))->area(), "\n";
