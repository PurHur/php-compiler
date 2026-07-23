<?php
// Issue #22450 — PHP 8.4 final plain property post-construct write (Zend/zend_object_handlers.c).
class F
{
    public final string $x = 'a';
}
$o = new F();
echo 'read=', $o->x, "\n";
try {
    $o->x = 'b';
    echo "WROTE\n";
} catch (Error $e) {
    echo 'BLOCKED ', $e->getMessage(), "\n";
}

class G
{
    public final string $y;

    public function __construct()
    {
        $this->y = 'c';
    }
}
$g = new G();
try {
    $g->y = 'd';
    echo "WROTE2\n";
} catch (Error $e) {
    echo 'BLOCKED2 ', $e->getMessage(), "\n";
}
