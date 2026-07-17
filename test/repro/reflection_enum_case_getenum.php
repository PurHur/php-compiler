<?php
error_reporting(E_ALL);
enum Suit { case Hearts; case Spades; }
enum Status: string { case Ok = 'ok'; }
$u = new ReflectionEnumUnitCase(Suit::class, 'Hearts');
$b = new ReflectionEnumBackedCase(Status::class, 'Ok');
foreach (['unit' => $u, 'backed' => $b] as $label => $c) {
    try {
        echo $label . '_enum=' . $c->getEnum()->getName() . "\n";
    } catch (Throwable $e) {
        echo $label . '_err=' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    echo $label . '_has=' . (method_exists($c, 'getEnum') ? '1' : '0') . "\n";
    try {
        echo $label . '_decl=' . $c->getDeclaringClass()->getName() . "\n";
    } catch (Throwable $e) {
        echo $label . '_decl_err=' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    try {
        echo $label . '_isEnumCase=' . ($c->isEnumCase() ? '1' : '0') . "\n";
    } catch (Throwable $e) {
        echo $label . '_isEnumCase_err=' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    try {
        echo $label . '_mods=' . $c->getModifiers() . "\n";
    } catch (Throwable $e) {
        echo $label . '_mods_err=' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    echo $label . '_parent=' . (get_parent_class($c) ?: '0') . "\n";
    echo $label . '_instanceof_rcc=' . ($c instanceof ReflectionClassConstant ? '1' : '0') . "\n";
}
