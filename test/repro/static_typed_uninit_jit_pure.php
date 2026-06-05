<?php

class C {
    public static int $x;
}

function f(): void {
    echo C::$x, "\n";
}

f();
