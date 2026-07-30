--TEST--
fsync/fdatasync Reflection stream + named stream: + bool return (#23406, file.stub.php)
--FILE--
<?php
foreach (['fsync', 'fdatasync'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' arity=', $r->getNumberOfParameters(),
        ' required=', $r->getNumberOfRequiredParameters(),
        ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(), '|', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
    }
    $tmp = tmpfile();
    echo '  named=', var_export($f(stream: $tmp), true), "\n";
    fclose($tmp);
}
?>
--EXPECT--
fsync arity=1 required=1 ret=bool
  stream|NONE
  named=true
fdatasync arity=1 required=1 ret=bool
  stream|NONE
  named=true
