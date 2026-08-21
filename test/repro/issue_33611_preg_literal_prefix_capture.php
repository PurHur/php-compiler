<?php

declare(strict_types=1);

// AOT: literal prefix + capturing groups must populate $matches (#33611).
preg_match('/a(b)/', 'ab', $m1);
echo $m1[0], '|', $m1[1], "\n";
preg_match('/a(b)(c)/', 'abc', $m2);
echo $m2[0], '|', $m2[1], '|', $m2[2], "\n";
preg_match('/(a)(b)/', 'ab', $m3);
echo $m3[0], '|', $m3[1], '|', $m3[2], "\n";
