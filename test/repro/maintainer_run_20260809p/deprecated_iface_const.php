<?php
interface I {
    #[\Deprecated(message: "use Y")]
    public const X = 1;
}
class C implements I {}
echo C::X;
