--TEST--
Language: #[\Deprecated] on trait method names using class, not trait (#29392, Zend/zend_attributes.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
ini_set('error_reporting', '32767');
ini_set('display_errors', '0');

trait Tr {
    #[\Deprecated('old')]
    function m() {
        return 1;
    }
}
class C {
    use Tr;
}

trait TrHello {
    #[\Deprecated('old')]
    function Hello() {
        return 2;
    }
}
class C2 {
    use TrHello;
}

trait TrAlias {
    #[\Deprecated('old')]
    function m() {
        return 3;
    }
}
class C3 {
    use TrAlias { m as other; }
}

echo (new C)->m(), "\n";
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";

echo (new C2)->Hello(), "\n";
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";

echo (new C3)->other(), "\n";
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
--EXPECT--
1
Method C::m() is deprecated, old
2
Method C2::Hello() is deprecated, old
3
Method C3::other() is deprecated, old
