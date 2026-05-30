--TEST--
language: Closure::bind() static method rebinds $this (issue #3673)
--FILE--
<?php
class C {
    private function sec(): string { return 'ok'; }
}
$c = new C;
$f = Closure::bind(function (): string { return $this->sec(); }, $c, C::class);
echo $f(), "\n";
--EXPECT--
ok
