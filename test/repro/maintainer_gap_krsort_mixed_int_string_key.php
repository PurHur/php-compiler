<?php

declare(strict_types=1);

$a = [0 => 'a', '0' => 'b'];
krsort($a);
echo json_encode($a), "\n";
