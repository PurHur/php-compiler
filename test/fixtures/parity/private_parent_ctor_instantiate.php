<?php
abstract class A {
    private function __construct() {}
}
class B extends A {}
new B();
