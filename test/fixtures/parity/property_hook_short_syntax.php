<?php
class C {
    public int $p {
        get => 1;
    }
}
echo (new C)->p, "\n";
