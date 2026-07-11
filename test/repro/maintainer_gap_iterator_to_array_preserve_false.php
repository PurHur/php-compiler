<?php
$packed = iterator_to_array(new ArrayObject(['a' => 1, 'b' => 2]), false);
echo json_encode(array_values($packed)), "\n";
