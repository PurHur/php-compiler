<?php
// Repro #32101 — plain include must mark path for subsequent include_once (Zend/zend_execute.c).
$f = sys_get_temp_dir() . '/phpc_once_ret_' . getmypid() . '.php';
file_put_contents($f, '<?php echo "RUN\n"; return 42;');
echo 'include1=';
var_export(include $f);
echo "\n";
echo 'include_once2=';
var_export(include_once $f);
echo "\n";
@unlink($f);
