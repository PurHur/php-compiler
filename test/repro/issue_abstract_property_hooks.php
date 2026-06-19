<?php
abstract class A {
    abstract public string $label { get; }
}
final class C extends A {
    public string $label { get => 'child'; }
}
echo (new C())->label, "\n";
