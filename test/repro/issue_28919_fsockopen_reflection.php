<?php
foreach (['fsockopen', 'pfsockopen'] as $n) {
    $r = new ReflectionFunction($n);
    foreach ($r->getParameters() as $p) {
        $def = '';
        if ($p->isOptional()) {
            try {
                $def = '=' . json_encode($p->getDefaultValue());
            } catch (Throwable $e) {
                $def = '=?';
            }
        }
        echo $n, ' $', $p->getName(),
            ' type=', ($p->hasType() ? (string) $p->getType() : 'none'),
            ' byref=', ($p->isPassedByReference() ? 'y' : 'n'),
            $def, "\n";
    }
    echo $n, ' ret=', ($r->hasReturnType() ? (string) $r->getReturnType() : 'none'), "\n";
}
try {
    $errno = null;
    $errstr = null;
    @fsockopen(hostname: '127.0.0.1', port: 1, error_code: $errno, error_message: $errstr, timeout: 0.1);
    echo "named ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $errno = null;
    $errstr = null;
    @fsockopen(hostname: '127.0.0.1', port: 1, errno: $errno, errstr: $errstr, timeout: 0.1);
    echo "legacy named ok\n";
} catch (Throwable $e) {
    echo 'legacy:', get_class($e), ':', $e->getMessage(), "\n";
}
