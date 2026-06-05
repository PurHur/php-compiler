<?php
class Base { public function m(): string { return 'base'; } }
class Child extends Base {
    #[\Override]
    public function m(): string { return 'child'; }
}
echo (new Child())->m() . "\n";
