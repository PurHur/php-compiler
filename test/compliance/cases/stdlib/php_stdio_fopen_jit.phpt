--TEST--
stdlib php://stdin / php://stdout / php://stderr fopen JIT (#4648)
--JIT--
--FILE--
<?php
foreach (['php://stdin', 'php://stdout', 'php://stderr'] as $uri) {
    $h = @fopen($uri, 'r');
    echo $uri, '=', var_export(is_resource($h), true), "\n";
    if (is_resource($h)) {
        fclose($h);
    }
}
$out = fopen('php://stdout', 'w');
fwrite($out, "stdout_ok\n");
--EXPECT--
php://stdin=true
php://stdout=true
php://stderr=true
stdout_ok
