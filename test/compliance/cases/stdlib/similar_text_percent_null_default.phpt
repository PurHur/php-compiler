--TEST--
similar_text Reflection $percent default null (#25361, ext/standard/string.stub.php)
--FILE--
<?php
$p = (new ReflectionFunction('similar_text'))->getParameters()[2];
$t = $p->getType();
echo 'similar_text ', $p->getName(),
    ' type=', null !== $t ? (string) $t : 'none',
    ' allowsNull=', (int) $p->allowsNull(),
    ' def=', var_export($p->getDefaultValue(), true),
    PHP_EOL;
echo 'result=', similar_text('aaa', 'aab'), PHP_EOL;
?>
--EXPECT--
similar_text percent type=none allowsNull=1 def=NULL
result=2
