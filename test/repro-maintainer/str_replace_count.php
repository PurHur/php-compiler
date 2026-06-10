<?php

declare(strict_types=1);

$c = 0;
echo str_replace('a', 'b', 'aba', $c), ' count=', $c, "\n";

$c = 0;
echo str_ireplace('A', 'b', 'AbA', $c), ' count=', $c, "\n";

$c = 0;
$r = str_replace('a', 'b', ['aba', 'aaa'], $c);
echo json_encode($r), ' count=', $c, "\n";
