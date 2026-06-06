<?php

class C {
    public static int $x = 1;
}

try {
    unset(C::$x);
    echo "unset succeeded\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
