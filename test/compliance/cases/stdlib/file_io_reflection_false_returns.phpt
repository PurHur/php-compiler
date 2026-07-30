--TEST--
stdlib file I/O Reflection return unions match php-src (#25509)
--FILE--
<?php
foreach (['file_get_contents', 'file_put_contents', 'fread', 'fwrite', 'fgets'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
$r = new ReflectionFunction('file_put_contents');
foreach ($r->getParameters() as $p) {
    echo '  ', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '?', ' opt=', $p->isOptional() ? 'Y' : 'N';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
echo 'missing=', var_export(@file_get_contents('/no/such/phpc-fget-25509.txt')), "\n";
?>
--EXPECT--
file_get_contents ret=string|false
file_put_contents ret=int|false
fread ret=string|false
fwrite ret=int|false
fgets ret=string|false
  filename type=string opt=N
  data type=mixed opt=N
  flags type=int opt=Y def=0
  context type=? opt=Y def=NULL
missing=false
