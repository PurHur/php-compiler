<?php
interface I {
    abstract public static function f(): void;
}
class C implements I {
    public static function f(): void { echo "impl\n"; }
}
