<?php
declare(strict_types=1);

$e = new Exception('msg', 42);
$vars = get_mangled_object_vars($e);
ksort($vars);
foreach (array_keys($vars) as $k) {
    echo json_encode($k), "\n";
}
