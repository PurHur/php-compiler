--TEST--
Language: virtual property hook with default initializer must compile-error (#16861, zend_verify_hooked_property)
--FILE--
<?php
class C {
    public string $label = 'default' {
        get => 'virtual';
    }
}
--EXPECT_EXIT--
255
