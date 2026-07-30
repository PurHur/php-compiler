<?php
// #25259 — fputcsv Reflection optionality + defaults vs Zend (file.stub.php).
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
