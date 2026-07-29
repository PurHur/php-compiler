<?php
/**
 * Issue #24790 — class_exists('Override') before a bad #[\Override] must still compile-fatal.
 * Root cause: class decls after runtime ops land in CFG successor blocks; OverrideValidator
 * previously only scanned main->cfg->children.
 */
echo 'Override_class=', class_exists('Override', false) ? '1' : '0', "\n";
class A
{
    public function f(): int
    {
        return 1;
    }
}
class C extends A
{
    #[\Override]
    public function g(): int
    {
        return 3;
    }
}
echo "C_ok\n";
