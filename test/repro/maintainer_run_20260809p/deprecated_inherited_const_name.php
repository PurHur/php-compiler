<?php
class A {
    #[\Deprecated(message: 'gone')]
    public const X = 1;
}
class B extends A {}
echo B::X;
