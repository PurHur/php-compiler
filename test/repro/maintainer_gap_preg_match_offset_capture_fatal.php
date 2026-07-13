<?php

declare(strict_types=1);

preg_match('/(a)/', 'a', $m, PREG_OFFSET_CAPTURE);
echo 'a:', $m[1][0], "\n";

preg_match('/(\w+)/', 'abc', $m2, PREG_OFFSET_CAPTURE, -1);
echo 'c:', $m2[1][0], ':', $m2[1][1], "\n";
