<?php
$ao = new ArrayObject(['x' => (object)['a' => 1]]);
echo serialize($ao), "\n";
