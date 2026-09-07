<?php
/**
 * AOT: is_resource(fopen('php://memory')) must be true (#36382 / #23777).
 * Zend/VM already green; thin AOT used to NestedJIT-hang or return false.
 */
$fh = fopen('php://memory', 'r+');
var_export(is_resource($fh));
echo "\n";
if (is_resource($fh)) {
    fwrite($fh, 'hello');
    rewind($fh);
    echo stream_get_contents($fh), "\n";
    fclose($fh);
} else {
    echo "NOT_RESOURCE\n";
}
