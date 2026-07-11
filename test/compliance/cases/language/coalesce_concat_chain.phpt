--TEST--
Language: chained concat with ?? operands reads prior concat results (#9973, #10430)
--FILE--
<?php
$a = [0 => 1, 1 => 1];
echo ($a[0] ?? 'x') . 'x' . ($a[1] ?? 'y'), "\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$fromString = getimagesizefromstring($png);
echo ($fromString[0] ?? 'w') . 'x' . ($fromString[1] ?? 'h'), "\n";
--EXPECT--
1x1
1x1
