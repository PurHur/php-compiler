--TEST--
ftp_append Reflection FTP\Connection stubs (#27686, ftp.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('ftp_append');
echo 'ret=', (string) ($r->getReturnType() ?? 'untyped'), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', (string) ($p->getType() ?? '?'), $p->isOptional() ? ' opt' : '', "\n";
}
$mode = $r->getParameters()[3];
echo 'mode_default=', var_export($mode->getDefaultValue(), true);
echo ' const=', var_export($mode->getDefaultValueConstantName(), true), "\n";
?>
--EXPECT--
ret=bool
ftp:FTP\Connection
remote_filename:string
local_filename:string
mode:int opt
mode_default=2 const='FTP_BINARY'
