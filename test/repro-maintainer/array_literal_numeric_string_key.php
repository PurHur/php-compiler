<?php
// Zend: array literal "123" and 123 share one slot; last value wins (#4151).
$a = ['123' => 1, 123 => 2];
echo $a[123], "\n";
$b = [123 => 1, '123' => 2];
echo $b[123], "\n";
