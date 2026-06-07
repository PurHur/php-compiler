<?php
class C {
    public const A = self::B + 1;
    public const B = 1;
}
echo C::A;
