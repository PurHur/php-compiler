--TEST--
stdlib fsockopen/pfsockopen Reflection stubs (#28919, basic_functions.stub.php)
--FILE--
<?php
foreach (['fsockopen', 'pfsockopen'] as $n) {
    $r = new ReflectionFunction($n);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $def = '';
        if ($p->isOptional()) {
            try {
                $def = '=' . json_encode($p->getDefaultValue());
            } catch (Throwable $e) {
                $def = '=?';
            }
        }
        $ps[] = $p->getName() . ':' . ($p->hasType() ? (string) $p->getType() : '?')
            . ($p->isPassedByReference() ? '&' : '') . $def;
    }
    echo $n, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'untyped',
        ' [', implode(', ', $ps), ']', PHP_EOL;
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
?>
--EXPECT--
fsockopen ret=untyped [hostname:string, port:int=-1, error_code:?&=null, error_message:?&=null, timeout:?float=null]
pfsockopen ret=untyped [hostname:string, port:int=-1, error_code:?&=null, error_message:?&=null, timeout:?float=null]
named ok
legacy:Error:Unknown named parameter $errno
