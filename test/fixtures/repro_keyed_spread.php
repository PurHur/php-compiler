<?php
$src = ['label' => 'L', 'a' => 1, 'b' => 2];
['label' => $label, ...$rest] = $src;
ksort($rest);
echo $label, "\n";
echo json_encode($rest), "\n";
