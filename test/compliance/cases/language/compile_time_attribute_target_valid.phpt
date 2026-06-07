--TEST--
Language: #[\CompileTime] on class constant compiles (#7300)
--FILE--
<?php
class C {
    #[\CompileTime]
    public const X = 9;
}
echo C::X, "\n";
--EXPECT--
9
