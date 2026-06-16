<?php
enum E: int { case A = 1; }
$a = [E::A => 'v'];
echo $a[E::A], "\n";
