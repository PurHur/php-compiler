--TEST--
Language: #[\Deprecated] trait method inherited cites using parent, not child (#29392)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
trait Tr {
    #[\Deprecated('old')]
    function m() {
        return 1;
    }
}
class P {
    use Tr;
}
class Ch extends P {}

ini_set('error_reporting', '32767');
ini_set('display_errors', '0');

echo (new Ch)->m(), "\n";
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
--EXPECT--
1
Method P::m() is deprecated, old
