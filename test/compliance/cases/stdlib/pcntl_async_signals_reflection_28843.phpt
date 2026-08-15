--TEST--
stdlib pcntl_async_signals Reflection return bool + ?bool $enable (#28843, ext/pcntl/pcntl.stub.php)
--FILE--
<?php
declare(strict_types=1);

if (!function_exists('pcntl_async_signals')) {
    echo "skip\n";
    exit(0);
}

$r = new ReflectionFunction('pcntl_async_signals');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '-';
    if ($p->isDefaultValueAvailable()) {
        echo ' default=', var_export($p->getDefaultValue(), true);
    }
    echo PHP_EOL;
}
?>
--EXPECT--
return=bool
enable type=?bool default=NULL
