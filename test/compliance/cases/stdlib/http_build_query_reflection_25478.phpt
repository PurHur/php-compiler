--TEST--
stdlib http_build_query Reflection object|array $data and ?string $arg_separator (#25478, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('http_build_query');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->getType() ?: 'none';
    if ($p->isDefaultValueAvailable()) {
        echo ' def=';
        var_export($p->getDefaultValue());
    }
    echo "\n";
}
?>
--EXPECT--
data type=object|array
numeric_prefix type=string def=''
arg_separator type=?string def=NULL
encoding_type type=int def=1
