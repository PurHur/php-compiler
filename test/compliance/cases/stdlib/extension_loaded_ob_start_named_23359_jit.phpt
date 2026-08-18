--TEST--
JIT: extension_loaded/ob_start named Zend stub args (issue #23359)
--FILE--
<?php
var_export(extension_loaded(extension: 'standard'));
echo PHP_EOL;
try {
    extension_loaded(extension_name: 'standard');
    echo "el_legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
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
true
Unknown named parameter $extension_name
started=1 buf=inbuf
Unknown named parameter $user_function
