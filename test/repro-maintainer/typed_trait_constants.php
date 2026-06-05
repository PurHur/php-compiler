<?php
trait TGood {
    public const int X = 1;
}

final class C {
    use TGood;
}

echo C::X, "\n";
