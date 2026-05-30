--TEST--
duplicate trait class constants fatal on class declaration (issue #3431)
--FILE--
<?php
trait T1 {
    public const N = 1;
}
trait T2 {
    public const N = 2;
}
class C {
    use T1, T2;
}
echo "unreachable\n";
--EXPECT_EXIT--
255
