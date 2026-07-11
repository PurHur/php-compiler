--TEST--
chown()/chgrp() failure var_export in echo concat (#16272, ext/standard/filestat.c)
--FILE--
<?php

declare(strict_types=1);

$path = '/nope/' . getmypid();

$nested = chown($path, getmyuid());
echo 'nested: ' . var_export($nested, true) . "\n";

$u = getmyuid();
$control = chown($path, $u);
echo 'control: ' . var_export($control, true) . "\n";

$nestedGrp = chgrp($path, getmygid());
echo 'chgrp nested: ' . var_export($nestedGrp, true) . "\n";
--EXPECT--
nested: false
control: false
chgrp nested: false
