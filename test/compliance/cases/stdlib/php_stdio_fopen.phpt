--TEST--
stdlib php://stdin / php://stdout / php://stderr fopen (issue #4648, ext/standard/streams.c)
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
