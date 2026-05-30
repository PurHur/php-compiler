--TEST--
Language: __METHOD__ in namespaced function scope (#3595)
--FILE--
<?php
namespace App\Lib;

function helper(): string {
    return __METHOD__;
}
echo helper(), "\n";
--EXPECT--
App\Lib\helper
