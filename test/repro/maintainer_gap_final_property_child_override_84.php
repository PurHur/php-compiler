<?php
// Issue #23824 — child must not override parent final plain property (PHP 8.4).
class ParentFinal
{
    public final string $x = 'a';
}
class Child extends ParentFinal
{
    public string $x = 'b';
}
echo "override=ok\n";
