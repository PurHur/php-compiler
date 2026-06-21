--TEST--
stdlib version_compare() — operator: named parameter (#10319, ext/standard/versioning.c)
--FILE--
<?php
declare(strict_types=1);

var_export(version_compare('8.4.0', '8.3.0', operator: '>='));
echo "\n";
--EXPECT--
true
