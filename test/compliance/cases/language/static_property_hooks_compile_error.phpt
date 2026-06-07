--TEST--
Language: static property hooks compile and run on direct class (#6931)
--FILE--
<?php
declare(strict_types=1);

class C {
    public static int $x {
        get => 1;
    }
}
echo C::$x, "\n";
--EXPECT--
1
