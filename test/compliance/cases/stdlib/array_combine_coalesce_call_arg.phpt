--TEST--
stdlib array_combine() — coalesce-wrapped keys/values inline call args (#17981)
--FILE--
<?php
$keys = ['a', 'b'];
$values = [1, 2];
$c = array_combine($keys ?? [], $values ?? []);
echo $c['a'], '|', $c['b'], "\n";
--EXPECT--
1|2
