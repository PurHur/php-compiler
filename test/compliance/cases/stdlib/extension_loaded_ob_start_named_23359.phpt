--TEST--
extension_loaded/ob_start Zend stub names + named args (VM, issue #23359)
--FILE--
<?php
$rf = new ReflectionFunction('extension_loaded');
echo 'el_ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'NONE', PHP_EOL;
foreach ($rf->getParameters() as $p) {
    echo 'el:', $p->getName(),
        $p->isOptional() ? '=' : '',
        ':', $p->hasType() ? (string) $p->getType() : 'NONE', PHP_EOL;
}
var_export(extension_loaded(extension: 'standard'));
echo PHP_EOL;
try {
    extension_loaded(extension_name: 'standard');
    echo "el_legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
$rf = new ReflectionFunction('ob_start');
echo 'ob_ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'NONE', PHP_EOL;
foreach ($rf->getParameters() as $p) {
    echo 'ob:', $p->getName(),
        $p->isOptional() ? '=' : '',
        ':', $p->hasType() ? (string) $p->getType() : 'NONE', PHP_EOL;
}
$started = ob_start(callback: null);
echo 'inbuf';
$buf = ob_get_clean();
echo 'started=', $started ? '1' : '0', ' buf=', $buf, PHP_EOL;
try {
    ob_start(user_function: null);
    echo "ob_legacy accepted\n";
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
?>
--EXPECT--
el_ret=bool
el:extension:string
true
Unknown named parameter $extension_name
ob_ret=bool
ob:callback=:?callable
ob:chunk_size=:int
ob:flags=:int
started=1 buf=inbuf
Unknown named parameter $user_function
