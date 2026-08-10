--TEST--
ftp_mlsd Reflection FTP\Connection stubs (#27735, ftp.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('ftp_mlsd');
echo 'ret=', (string) ($r->getReturnType() ?? 'untyped'), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', (string) ($p->getType() ?? '?'), $p->isOptional() ? ' opt' : '', "\n";
}
?>
--EXPECT--
ret=array|false
ftp:FTP\Connection
directory:string
