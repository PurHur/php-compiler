<?php
trait T { final public const X = 1; }
class C { use T; public const X = 2; }
var_export(C::X);
