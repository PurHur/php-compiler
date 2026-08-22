--TEST--
iptcparse Reflection array|false + $iptc_block (VM, issue #27782, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('iptcparse');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
echo 'param=', $r->getParameters()[0]->getName(), "\n";
var_export(iptcparse(''));
echo "\n";
var_export(iptcparse(iptc_block: ''));
echo "\n";
try {
    iptcparse(iptcdata: '');
    echo "legacy accepted\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
return=array|false
param=iptc_block
false
false
Unknown named parameter $iptcdata
