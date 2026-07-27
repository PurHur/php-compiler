--TEST--
gethostbyname named args (VM, issue #23492)
--FILE--
<?php
$ip = gethostbyname(hostname: 'localhost');
var_export(is_string($ip));
echo "\n";
$rf = new ReflectionFunction('gethostbyname');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'gethostbyname:', implode(',', $names), "\n";
--EXPECT--
true
gethostbyname:hostname
