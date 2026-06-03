--TEST--
Language: duplicate trait static properties fatal on class declaration (#4670)
--FILE--
<?php
trait T1 {
    public static $n = 1;
}
trait T2 {
    public static $n = 2;
}
class C {
    use T1, T2;
}
echo "unreachable\n";
--EXPECT_EXIT--
255
