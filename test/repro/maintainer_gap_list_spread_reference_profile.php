<?php

$src = [1, 2, 3, 4];
[$a, ...$rest] = $src;
echo $a, ':', implode(',', $rest), "\n";
