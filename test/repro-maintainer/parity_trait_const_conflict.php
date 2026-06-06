<?php
trait T { public const X = 1; }
class C { use T; public const X = 2; }
