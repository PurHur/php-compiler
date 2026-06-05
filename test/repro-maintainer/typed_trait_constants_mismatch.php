<?php
trait TBad {
    public const int X = '1';
}

final class C {
    use TBad;
}
