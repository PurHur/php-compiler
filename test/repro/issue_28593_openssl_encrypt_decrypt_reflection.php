<?php
/**
 * Issue #28593 — openssl_encrypt/decrypt Reflection matches openssl.stub.php
 * (string $iv = "", encrypt &$tag untyped, string $aad = "").
 */
function dump(string $f): void
{
    echo "== $f ==\n";
    foreach ((new ReflectionFunction($f))->getParameters() as $i => $p) {
        $t = $p->hasType() ? (string) $p->getType() : 'untyped';
        $def = $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '<none>';
        $ref = $p->isPassedByReference() ? '&' : '';
        echo "[$i] {$ref}{$p->getName()}:$t def=$def\n";
    }
}
dump('openssl_decrypt');
dump('openssl_encrypt');
