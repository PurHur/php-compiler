--TEST--
Language: asymmetric visibility — duplicate public public(set) compile fatal (#6774, #6861, zend_compile.c)
--FILE--
<?php
class C {
    public public(set) string $x = 'a';
}
echo "ok\n";
--EXPECT_EXIT--
255
--FILE--
<?php
class C {
    protected protected(set) string $x = 'b';
}
echo "ok\n";
--EXPECT_EXIT--
255
