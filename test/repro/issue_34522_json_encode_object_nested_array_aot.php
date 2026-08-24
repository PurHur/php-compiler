<?php

declare(strict_types=1);

// Implicit FORCE_OBJECT for object wire must not leak into nested array values (#34522).
echo json_encode((object) ['a' => 1, 'b' => [2]]), "\n";
echo json_encode(new ArrayObject(['x' => [1, 2]])), "\n";
$o = (object) ['x' => [1, 2]];
echo json_encode($o), "\n";
// Explicit JSON_FORCE_OBJECT still applies to nested arrays.
echo json_encode([[1, 2]], JSON_FORCE_OBJECT), "\n";
