<?php
trait T1 { public const X = 1; }
trait T2 { public const X = 2; }
class C { use T1, T2; }
echo C::X, "\n";
