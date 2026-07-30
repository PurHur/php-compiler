--TEST--
implode/join Reflection matches php-src stub (#24811, ext/standard/string.stub.php)
--FILE--
<?php
foreach (['implode', 'join'] as $fn) {
  $r = new ReflectionFunction($fn);
  echo "== $fn ==\n";
  foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', (string) $p->getType(),
      ' opt=', (int) $p->isOptional(),
      ' defAvail=', (int) $p->isDefaultValueAvailable();
    if ($p->isDefaultValueAvailable()) {
      echo ' ', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
  }
}
echo 'legacy=', implode(['a', 'b']), "\n";
echo 'join_legacy=', join(['x', 'y']), "\n";
echo 'two=', implode(',', ['a', 'b']), "\n";
?>
--EXPECT--
== implode ==
separator type=array|string opt=0 defAvail=0
array type=?array opt=1 defAvail=1 NULL
== join ==
separator type=array|string opt=0 defAvail=0
array type=?array opt=1 defAvail=1 NULL
legacy=ab
join_legacy=xy
two=a,b
