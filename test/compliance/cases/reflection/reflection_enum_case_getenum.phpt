--TEST--
Stdlib: ReflectionEnumUnitCase/BackedCase::getEnum() + ClassConstant surface (#19785)
--FILE--
<?php
error_reporting(E_ALL);
enum Suit { case Hearts; case Spades; }
enum Status: string { case Ok = 'ok'; }
$u = new ReflectionEnumUnitCase(Suit::class, 'Hearts');
$b = new ReflectionEnumBackedCase(Status::class, 'Ok');
foreach (['unit' => $u, 'backed' => $b] as $label => $c) {
    echo $label . '_enum=' . $c->getEnum()->getName() . "\n";
    echo $label . '_has=' . (method_exists($c, 'getEnum') ? '1' : '0') . "\n";
    echo $label . '_decl=' . $c->getDeclaringClass()->getName() . "\n";
    echo $label . '_decl_class=' . get_class($c->getDeclaringClass()) . "\n";
    echo $label . '_isEnumCase=' . ($c->isEnumCase() ? '1' : '0') . "\n";
    echo $label . '_mods=' . $c->getModifiers() . "\n";
    echo $label . '_parent=' . get_parent_class($c) . "\n";
    echo $label . '_instanceof_rcc=' . ($c instanceof ReflectionClassConstant ? '1' : '0') . "\n";
}
--EXPECT--
unit_enum=Suit
unit_has=1
unit_decl=Suit
unit_decl_class=ReflectionEnum
unit_isEnumCase=1
unit_mods=1
unit_parent=ReflectionClassConstant
unit_instanceof_rcc=1
backed_enum=Status
backed_has=1
backed_decl=Status
backed_decl_class=ReflectionEnum
backed_isEnumCase=1
backed_mods=1
backed_parent=ReflectionEnumUnitCase
backed_instanceof_rcc=1
