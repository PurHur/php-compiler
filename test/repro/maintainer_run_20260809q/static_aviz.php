<?php
// #29389 — static + asymmetric visibility message parity (Zend 8.4)
class C {
    public private(set) static int $x = 1;
}
echo "unreachable\n";
