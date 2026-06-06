--TEST--
Language: asymmetric visibility — set visibility must not exceed read visibility (#6589, Zend/zend_API.c)
--FILE--
<?php
class C {
    protected public(set) string $x = 'a';
}
--EXPECT_EXIT--
255
