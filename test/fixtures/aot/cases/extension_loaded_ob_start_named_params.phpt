--TEST--
AOT: extension_loaded(extension:) / ob_start(callback:) named args (#23359)
--FILE--
<?php
extension_loaded(extension: 'standard');
echo "el_ok\n";
$started = ob_start(callback: null);
echo 'inbuf';
$buf = ob_get_clean();
echo (true === $started && 'inbuf' === $buf) ? 'ob_ok' : 'ob_bad';
echo "\n";
--EXPECT--
el_ok
ob_ok
