--TEST--
putenv named assignment argument (VM, issue #23258)
--FILE--
<?php
$rf = new ReflectionFunction('putenv');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'putenv:', implode(',', $names), PHP_EOL;
var_export(putenv(assignment: 'PHPC_PUTENV_NAMED_23258=1'));
echo PHP_EOL;
echo getenv('PHPC_PUTENV_NAMED_23258'), PHP_EOL;
putenv('PHPC_PUTENV_NAMED_23258');
try {
    putenv(setting: 'PHPC_PUTENV_NAMED_23258=1');
    echo "setting accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
?>
--EXPECT--
putenv:assignment
true
1
Unknown named parameter $setting
