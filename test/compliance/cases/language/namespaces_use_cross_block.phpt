--TEST--
Language: namespace use imports persist across re-entered namespace blocks (#4425)
--FILE--
<?php
namespace N1;

use N2\C as Aliased;
use function N2\f as ff;
use const N2\K as KK;

class C { }

namespace N2;
const K = 123;
function f() { return __NAMESPACE__; }
class C { }

namespace N1;

echo Aliased::class, "\n";
echo ff(), "\n";
echo KK, "\n";
echo C::class, "\n";
echo \N2\C::class, "\n";
--EXPECT--
N2\C
N2
123
N1\C
N2\C
