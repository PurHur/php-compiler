<?php
/**
 * Issue #27122 / #24770 — FinalPropertyOverrideCheck must see Class_ ops that
 * land in CFG successor blocks after ternaries (not only main->cfg->children).
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer-gaps/final_prop_override_after_ternary.php
 *
 * Expect exit 255: Cannot override final property C::$name
 */
class C
{
    public final string $name = 'x';
}
true ? 1 : 0;
class D extends C
{
    public string $name = 'y';
}
echo (new D())->name, "\n";
