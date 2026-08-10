<?php
/**
 * #29552 — abstract class using abstract trait method must stay subclassable.
 * Preceding runtime opcodes (error_reporting) must not finalize deferred parent
 * inheritance before the trait-using abstract parent is declared.
 */
error_reporting(E_ALL);
trait TAbs29552
{
    abstract public function f();
}
abstract class AAbs29552
{
    use TAbs29552;
}
class BAbs29552 extends AAbs29552
{
    public function f()
    {
        return 1;
    }
}
echo (new BAbs29552())->f(), "\n";
