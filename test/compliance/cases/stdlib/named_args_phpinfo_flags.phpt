--TEST--
phpinfo Reflection + Zend named flags (VM, issue #24550)
--FILE--
<?php
$r = new ReflectionFunction('phpinfo');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'names=', implode(',', $names), PHP_EOL;
ob_start();
$ok = phpinfo(flags: INFO_GENERAL);
$out = ob_get_clean();
echo 'flags=', ($ok && strlen($out) > 10) ? 'ok' : 'bad', PHP_EOL;
try {
    ob_start();
    phpinfo(what: INFO_GENERAL);
    ob_end_clean();
    echo "what accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
names=flags
flags=ok
Unknown named parameter $what
