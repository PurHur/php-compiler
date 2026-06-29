<?php

declare(strict_types=1);

$a = [0 => 'a', '0' => 'b'];
ksort($a);
echo json_encode($a), "\n";

$b = [0 => 'a', '0' => 'b'];
krsort($b);
echo json_encode($b), "\n";
