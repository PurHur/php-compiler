--TEST--
SPL SplFileInfo::__construct Reflection/named filename (#24505)
--FILE--
<?php
$r = new ReflectionMethod('SplFileInfo', '__construct');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params=', implode(',', $names), "\n";
$o = new SplFileInfo(filename: '/etc/hosts');
echo 'basename=', $o->getFilename() === 'hosts' ? 'ok' : 'bad', "\n";
try {
    new SplFileInfo(file_name: '/etc/hosts');
    echo "file_name=accepted\n";
} catch (Error $e) {
    echo 'file_name=rejected', "\n";
}
--EXPECT--
params=filename
basename=ok
file_name=rejected
