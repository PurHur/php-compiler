<?php
/**
 * #29566 — runtime prelude before a trait-using parent + subclass must not
 * fatal Class "A" not found. Same source-order subclass rule as #29552/#29599.
 */
echo "start\n";
trait TPrelude29566
{
    public function n()
    {
        return 1;
    }
}
class APrelude29566
{
    use TPrelude29566;
}
class BPrelude29566 extends APrelude29566
{
}
echo (new BPrelude29566())->n(), "\n";
