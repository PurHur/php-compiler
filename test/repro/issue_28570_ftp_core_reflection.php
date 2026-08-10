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
