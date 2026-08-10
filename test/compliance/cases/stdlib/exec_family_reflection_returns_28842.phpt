--TEST--
stdlib exec/system/passthru/shell_exec Reflection returns (#28842, basic_functions.stub.php)
--FILE--
<?php
foreach (['exec', 'system', 'passthru', 'shell_exec'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ', $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
}
?>
--EXPECT--
exec string|false
system string|false
passthru ?false
shell_exec string|false|null
