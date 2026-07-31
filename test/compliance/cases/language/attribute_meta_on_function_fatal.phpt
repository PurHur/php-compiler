--TEST--
Language: #[Attribute] on function — compile-time fatal (#25723)
--FILE--
<?php
#[Attribute]
function f() { return 1; }
echo f(), "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "Attribute" cannot target function (allowed targets: class)
