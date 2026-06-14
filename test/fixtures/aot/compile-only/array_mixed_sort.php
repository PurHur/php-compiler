<?php
// AOT compile-only (#4461): mixed ksort/asort keys and values.
$a = [2 => 'two', '10' => 'ten', 1 => 'one', 'a' => 'A'];
ksort($a);
$b = ['x' => 10, 'y' => '2', 'z' => 3];
asort($b);
