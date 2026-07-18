--TEST--
Language: #[\SensitiveParameter] on property — compile-time fatal (#20351)
--FILE--
<?php
class A {
    #[\SensitiveParameter]
    public string $secret = 'x';
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "SensitiveParameter" cannot target property (allowed targets: parameter)
