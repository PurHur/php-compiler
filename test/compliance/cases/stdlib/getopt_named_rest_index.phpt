--TEST--
stdlib getopt named rest_index + Reflection defaults (#25144)
--FILE--
<?php
$_SERVER['argv'] = ['prog', '-a', 'foo'];
$ri = -1;
var_export(getopt(short_options: 'a', rest_index: $ri));
echo ' ri=', $ri, "\n";
$p = (new ReflectionFunction('getopt'))->getParameters()[2];
echo 'rest=', $p->getName();
echo $p->isOptional() ? ' OPT' : ' REQ';
echo $p->isPassedByReference() ? ' REF' : '';
if ($p->isDefaultValueAvailable()) {
    echo '=', var_export($p->getDefaultValue(), true);
}
echo "\n";
--EXPECT--
array (
) ri=1
rest=rest_index OPT REF=NULL
