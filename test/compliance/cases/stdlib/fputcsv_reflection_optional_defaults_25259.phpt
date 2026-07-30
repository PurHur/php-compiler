--TEST--
fputcsv Reflection separator/enclosure/escape/eol optional defaults (#25259, file.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('fputcsv');
$req = 0;
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' opt=', (int) $p->isOptional();
    if ($p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
    if (!$p->isOptional()) {
        ++$req;
    }
}
echo 'required_count=', $req, "\n";
echo 'arity=', $r->getNumberOfParameters(), ' required=', $r->getNumberOfRequiredParameters(), "\n";

$tmp = tmpfile();
$n = fputcsv(stream: $tmp, fields: ['a', 'b'], separator: ';');
rewind($tmp);
echo 'named=', var_export(stream_get_contents($tmp), true), ' n=', $n, "\n";
fclose($tmp);
?>
--EXPECT--
stream opt=0
fields opt=0
separator opt=1 def=','
enclosure opt=1 def='"'
escape opt=1 def='\\'
eol opt=1 def='
'
required_count=2
arity=6 required=2
named='a;b
' n=4
