<?php
trait TA { public const FOO = 1; }
trait TB { public const FOO = 2; }
final class C {
    use TA, TB;
}
echo C::FOO, "\n";
