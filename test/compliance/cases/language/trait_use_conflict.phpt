--TEST--
horizontal trait method conflict (issue #144)
--FILE--
<?php
trait T1 {
    public function f(): int { return 1; }
}
trait T2 {
    public function f(): int { return 2; }
}
class C {
    use T1, T2;
}
echo (new C)->f(), "\n";
--EXPECT_EXIT--
255
