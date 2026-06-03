--TEST--
Language: trait composition — use trait inside trait body (#4971, zend_traits.c)
--FILE--
<?php
trait Base {
    public function base(): string {
        return 'base';
    }
}
trait Composed {
    use Base;
}
class C {
    use Composed;
}
echo (new C())->base(), "\n";
--EXPECT--
base
