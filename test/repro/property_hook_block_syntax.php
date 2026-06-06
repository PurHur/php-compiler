<?php
class C {
    public int $x {
        get {
            return 42;
        }
    }
}
echo (new C())->x, "\n";
