<?php
/**
 * Issue #22142 — #[\Override] without matching parent method on unset reference profile.
 * Zend 8.2 accepts (attribute inert); php-compiler must print ok without PHP_COMPILER_PROFILE.
 */
class A
{
    public function f(): void
    {
    }
}
class B extends A
{
    #[Override]
    public function g(): void
    {
    }
}
echo "ok\n";
