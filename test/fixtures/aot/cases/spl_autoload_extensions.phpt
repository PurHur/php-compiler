--TEST--
AOT spl_autoload_extensions() (#4256)
--FILE--
<?php
declare(strict_types=1);

echo spl_autoload_extensions(), "\n";
spl_autoload_extensions('.aot');
echo spl_autoload_extensions(), "\n";
--EXPECT--
.inc,.php
.aot
