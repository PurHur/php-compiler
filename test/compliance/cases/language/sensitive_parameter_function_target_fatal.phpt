--TEST--
Language: #[\SensitiveParameter] on function — compile-time fatal (#11638)
--FILE--
<?php
#[\SensitiveParameter]
function probe($x): void {}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "SensitiveParameter" cannot target function (allowed targets: parameter)
