--TEST--
openssl_encrypt/decrypt Reflection iv/aad/tag stubs (VM, issue #28593, openssl.stub.php)
--FILE--
<?php
function dump(string $f): void {
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
?>
--EXPECT--
== openssl_decrypt ==
[0] data:string def=<none>
[1] cipher_algo:string def=<none>
[2] passphrase:string def=<none>
[3] options:int def=0
[4] iv:string def=''
[5] tag:?string def=NULL
[6] aad:string def=''
== openssl_encrypt ==
[0] data:string def=<none>
[1] cipher_algo:string def=<none>
[2] passphrase:string def=<none>
[3] options:int def=0
[4] iv:string def=''
[5] &tag:untyped def=NULL
[6] aad:string def=''
[7] tag_length:int def=16
