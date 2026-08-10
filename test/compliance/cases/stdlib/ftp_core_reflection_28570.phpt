--TEST--
ftp connect/login/nlist Reflection FTP\Connection stubs (#28570, ftp.stub.php)
--FILE--
<?php
foreach (['ftp_connect', 'ftp_ssl_connect', 'ftp_login', 'ftp_nlist', 'ftp_close', 'ftp_pasv', 'ftp_get', 'ftp_put'] as $f) {
    $r = new ReflectionFunction($f);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $d = '';
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            $d = '='.var_export($p->getDefaultValue(), true);
            $c = $p->getDefaultValueConstantName();
            if ($c) {
                $d .= '('.$c.')';
            }
        }
        $ps[] = $p->getName().':'.(string) ($p->getType() ?? '?').($p->isOptional() ? ' opt' : '').$d;
    }
    echo $f, ' ret=', (string) ($r->getReturnType() ?? 'untyped'), ' [', implode(', ', $ps), "]\n";
}
?>
--EXPECT--
ftp_connect ret=FTP\Connection|false [hostname:string, port:int opt=21, timeout:int opt=90]
ftp_ssl_connect ret=FTP\Connection|false [hostname:string, port:int opt=21, timeout:int opt=90]
ftp_login ret=bool [ftp:FTP\Connection, username:string, password:string]
ftp_nlist ret=array|false [ftp:FTP\Connection, directory:string]
ftp_close ret=bool [ftp:FTP\Connection]
ftp_pasv ret=bool [ftp:FTP\Connection, enable:bool]
ftp_get ret=bool [ftp:FTP\Connection, local_filename:string, remote_filename:string, mode:int opt=2(FTP_BINARY), offset:int opt=0]
ftp_put ret=bool [ftp:FTP\Connection, remote_filename:string, local_filename:string, mode:int opt=2(FTP_BINARY), offset:int opt=0]
