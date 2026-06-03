--TEST--
list destructuring with keyed spread — ['k' => $v, ...$tail] = $assoc (#4889)
--FILE--
<?php
$src = ['label' => 'L', 'a' => 1, 'b' => 2];
['label' => $label, ...$rest] = $src;
ksort($rest);
echo $label, "\n";
echo json_encode($rest), "\n";
--EXPECT--
L
{"a":1,"b":2}
