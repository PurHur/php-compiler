<?php

class C {
    public static int $x;
}

try {
    echo C::$x;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
