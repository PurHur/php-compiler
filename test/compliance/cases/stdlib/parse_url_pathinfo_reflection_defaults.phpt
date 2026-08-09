--TEST--
stdlib parse_url/pathinfo Reflection component/flags defaults (#24857, ext/standard/basic_functions.stub.php)
--FILE--
<?php
foreach (['parse_url', 'pathinfo'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
    foreach ($r->getParameters() as $p) {
        echo $f, ' ', $p->getName();
        if ($p->hasType()) {
            echo ' :', $p->getType();
        }
        echo ' opt=', (int) $p->isOptional(),
            ' defAvail=', (int) $p->isDefaultValueAvailable();
        if ($p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}
$host = parse_url('https://example.com/a', component: PHP_URL_HOST);
echo 'named_host=', var_export($host, true), "\n";
$ext = pathinfo('/tmp/foo.txt', flags: PATHINFO_EXTENSION);
echo 'named_ext=', var_export($ext, true), "\n";
echo 'pathinfo_all=', PATHINFO_ALL, "\n";
?>
--EXPECT--
parse_url return=array|string|int|false|null
parse_url url :string opt=0 defAvail=0
parse_url component :int opt=1 defAvail=1 def=-1
pathinfo return=array|string
pathinfo path :string opt=0 defAvail=0
pathinfo flags :int opt=1 defAvail=1 def=15
named_host='example.com'
named_ext='txt'
pathinfo_all=15
