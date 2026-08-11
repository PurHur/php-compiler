--TEST--
AOT: strpbrk/strtok(null) under strict_types throws TypeError (#29784)
--FILE--
<?php
declare(strict_types=1);

try {
    var_export(strpbrk(null, 'a'));
    echo " strpbrk: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    var_export(strtok(null, ' '));
    echo " strtok: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strpbrk(): Argument #1 ($string) must be of type string, null given
strtok(): Argument #1 ($string) must be of type string, null given
