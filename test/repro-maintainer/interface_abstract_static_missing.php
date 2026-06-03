<?php
interface I {
    abstract public static function f(): void;
}
class C implements I {}
new C;
