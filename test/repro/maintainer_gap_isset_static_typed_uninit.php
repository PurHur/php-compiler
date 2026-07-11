<?php
class T {
    public static int $s;
}
if (!isset(T::$s)) {
    echo "ok\n";
} else {
    echo "fail\n";
}
