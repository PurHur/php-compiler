<?php
// Issue #9725 — virtual self-referential static property hook dispatch.
class C {
    public static int $x {
        get => self::$x + 1;
        set => self::$x = $value - 1;
    }
}
C::$x = 10;
echo C::$x, "\n";
