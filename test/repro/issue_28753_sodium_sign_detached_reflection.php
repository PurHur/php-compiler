<?php
/**
 * Repro for #28753 — sodium_crypto_sign_detached / verify_detached / box_seal
 * Reflection arity + named args (php-src ext/sodium/libsodium.stub.php).
 */
$funcs = [
    'sodium_crypto_sign_detached' => ['message', 'secret_key', 'string'],
    'sodium_crypto_sign_verify_detached' => ['signature', 'message', 'public_key', 'bool'],
    'sodium_crypto_box_seal' => ['message', 'public_key', 'string'],
];
foreach ($funcs as $f => $expect) {
    $retExpect = array_pop($expect);
    $r = new ReflectionFunction($f);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $type = $p->hasType() ? (string) $p->getType() : 'none';
        $names[] = $p->getName() . ':' . $type;
    }
    $ret = $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
    $ok = count($r->getParameters()) === count($expect)
        && $ret === $retExpect
        && implode(',', array_map(static fn ($n) => explode(':', $n)[0], $names)) === implode(',', $expect);
    echo $f, ' arity=', $r->getNumberOfParameters(), ' names=', implode(',', $names), ' ret=', $ret, $ok ? " ok\n" : " BAD\n";
}

$kp = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($kp);
$pk = sodium_crypto_sign_publickey($kp);
$sigPos = sodium_crypto_sign_detached('m', $sk);
$sigNamed = sodium_crypto_sign_detached(message: 'm', secret_key: $sk);
echo 'sign_detached_named=', ($sigPos === $sigNamed && strlen($sigPos) === 64) ? "ok\n" : "BAD\n";
$verify = sodium_crypto_sign_verify_detached(signature: $sigPos, message: 'm', public_key: $pk);
echo 'verify_named=', $verify ? "ok\n" : "BAD\n";

$bkp = sodium_crypto_box_keypair();
$bpk = sodium_crypto_box_publickey($bkp);
$sealedPos = sodium_crypto_box_seal('hello', $bpk);
$sealedNamed = sodium_crypto_box_seal(message: 'hello', public_key: $bpk);
echo 'box_seal_named=', (strlen($sealedPos) === strlen($sealedNamed) && strlen($sealedPos) > 0) ? "ok\n" : "BAD\n";
