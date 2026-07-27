<?php
// Issue #23683 — PHP 8.4 final plain property: inheritance-only (Zend 8.4.23/8.5.8).
// Writes succeed; ReflectionProperty::isFinal() is true; child override fatals separately.
class F
{
    public final string $x = 'a';
}
$o = new F();
echo 'read=', $o->x, "\n";
$o->x = 'b';
echo 'wrote=', $o->x, "\n";
echo 'isFinal=', (new ReflectionProperty('F', 'x'))->isFinal() ? '1' : '0', "\n";

class G
{
    public final string $y;

    public function __construct()
    {
        $this->y = 'c';
    }
}
$g = new G();
$g->y = 'd';
echo 'wrote2=', $g->y, "\n";
