--TEST--
stdlib trigger_error/user_error Reflection return true (Zend/zend_builtin_functions.stub.php; #28222)
--FILE--
<?php
foreach (['trigger_error', 'user_error'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, '|', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
    $p = $r->getParameters();
    echo $f, '_message=', $p[0]->getName(), '|', (string) $p[0]->getType(), "\n";
    echo $f, '_error_level=', $p[1]->getName(), '|', (string) $p[1]->getType(),
        '|def=', $p[1]->isDefaultValueAvailable() ? var_export($p[1]->getDefaultValue(), true) : '-',
        "\n";
}
?>
--EXPECT--
trigger_error|true
trigger_error_message=message|string
trigger_error_error_level=error_level|int|def=1024
user_error|true
user_error_message=message|string
user_error_error_level=error_level|int|def=1024
