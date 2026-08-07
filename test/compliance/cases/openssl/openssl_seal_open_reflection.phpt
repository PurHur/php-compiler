--TEST--
openssl_seal/openssl_open Reflection + Zend named args (VM, issue #28754)
--FILE--
<?php
function dump(string $f): void {
    $r = new ReflectionFunction($f);
    echo "== $f ==\n";
    echo 'required=', $r->getNumberOfRequiredParameters(), ' argc=', $r->getNumberOfParameters(), "\n";
    echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
    foreach ($r->getParameters() as $p) {
        echo ($p->isPassedByReference() ? '&' : ''), $p->getName();
        if ($p->hasType()) {
            echo ':', $p->getType();
        }
        echo $p->isOptional() ? ' OPT' : ' REQ';
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            echo '=', json_encode($p->getDefaultValue());
        }
        echo "\n";
    }
}
dump('openssl_seal');
dump('openssl_open');

$pk = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$pub = openssl_pkey_get_public(openssl_pkey_get_details($pk)['key']);
$sealed = null;
$ekeys = null;
$iv = null;
$len = openssl_seal(data: 'hi', sealed_data: $sealed, encrypted_keys: $ekeys, public_key: [$pub], cipher_algo: 'AES-128-CBC', iv: $iv);
echo 'named_seal=', is_int($len) && $len > 0 ? 'ok' : var_export($len, true), "\n";
$out = null;
$ok = openssl_open(data: $sealed, output: $out, encrypted_key: $ekeys[0], private_key: $pk, cipher_algo: 'AES-128-CBC', iv: $iv);
echo 'named_open=', ($ok && $out === 'hi') ? 'ok' : var_export([$ok, $out], true), "\n";

try {
    openssl_seal(data: 'x', sealdata: $s, ekeys: $e, pubkeys: [$pub], method: 'AES-128-CBC');
    echo "legacy seal accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    openssl_open(data: 'x', opendata: $o, ekey: 'y', privkey: $pk, method: 'AES-128-CBC');
    echo "legacy open accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
== openssl_seal ==
required=5 argc=6
ret=int|false
data:string REQ
&sealed_data REQ
&encrypted_keys REQ
public_key:array REQ
cipher_algo:string REQ
&iv OPT=null
== openssl_open ==
required=5 argc=6
ret=bool
data:string REQ
&output REQ
encrypted_key:string REQ
private_key REQ
cipher_algo:string REQ
iv:?string OPT=null
named_seal=ok
named_open=ok
Unknown named parameter $sealdata
Unknown named parameter $opendata
