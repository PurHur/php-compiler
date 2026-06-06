<?php
trait T { public function foo() {} }
class C { use T, T; }
