<?php
class Sq { public function __construct(public int $s) {} }
$q = new Sq(4);
echo $q->s, "\n";
