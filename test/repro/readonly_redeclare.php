<?php
class B {
    public int $x = 1;
}
class C extends B {
    public readonly int $x;
}
echo "compiled\n";
