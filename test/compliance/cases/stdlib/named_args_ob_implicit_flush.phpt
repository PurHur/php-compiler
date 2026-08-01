--TEST--
ob_implicit_flush named arguments + Reflection (VM, issue #24455)
--FILE--
<?php
$names = [];
foreach ((new ReflectionFunction('ob_implicit_flush'))->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
ob_implicit_flush(enable: true);
echo "enable_ok\n";
ob_implicit_flush(false);
echo "pos_ok\n";
try {
    ob_implicit_flush(flag: true);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
enable
enable_ok
pos_ok
Error
