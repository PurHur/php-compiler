<?php
/**
 * #27630 — sodium_memzero() Reflection: string &$string → void (ext/sodium/libsodium.stub.php).
 */
$r = new ReflectionFunction('sodium_memzero');
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    echo 'name=', $p->getName(), ' type=', $t ? (string) $t : 'none',
        ' byref=', $p->isPassedByReference() ? '1' : '0', "\n";
}
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$s = 'secret';
sodium_memzero($s);
echo 'after=', var_export($s, true), "\n";
