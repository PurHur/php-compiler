<?php
// Issue #23818 — ReflectionProperty::isFinal() for final plain properties (PHP 8.4).
class F
{
    public final string $x = 'a';
}
echo 'isFinal=', (new ReflectionProperty('F', 'x'))->isFinal() ? '1' : '0', "\n";
