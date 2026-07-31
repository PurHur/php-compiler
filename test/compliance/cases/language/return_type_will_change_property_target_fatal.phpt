--TEST--
Language: #[\ReturnTypeWillChange] on property — compile-time fatal (#25722)
--FILE--
<?php
class A
{
    #[\ReturnTypeWillChange]
    public $x = 1;
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "ReturnTypeWillChange" cannot target property (allowed targets: method)
