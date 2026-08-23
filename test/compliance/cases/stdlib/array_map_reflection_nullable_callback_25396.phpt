--TEST--
stdlib array_map Reflection callback is ?callable; null zip unchanged (#25396, ext/standard/array.stub.php)
--FILE--
<?php
$p = (new ReflectionFunction('array_map'))->getParameters()[0];
echo 'callback:', $p->hasType() ? (string) $p->getType() : 'none';
echo ' nullable=', ($p->hasType() && $p->getType()->allowsNull()) ? 'yes' : 'no';
echo ' optional=', $p->isOptional() ? 'yes' : 'no';
echo "\n";
echo json_encode(array_map(null, [1, 2])), "\n";
echo json_encode(array_map('intval', ['1', '2'])), "\n";
?>
--EXPECT--
callback:?callable nullable=yes optional=no
[1,2]
[1,2]
