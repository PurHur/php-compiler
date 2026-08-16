<?php
/**
 * #27853 — sodium_bin2base64()/sodium_base642bin() Reflection: string/id stubs + named args.
 */
foreach (['sodium_bin2base64', 'sodium_base642bin'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' arity=', $r->getNumberOfParameters(),
        ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
    foreach ($r->getParameters() as $p) {
        $def = '';
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            $def = '=' . var_export($p->getDefaultValue(), true);
        }
        echo '  ', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : '?', $def, "\n";
    }
}
$enc = sodium_bin2base64(string: 'hello', id: SODIUM_BASE64_VARIANT_ORIGINAL);
echo 'named_bin=', $enc, "\n";
echo 'pos_bin=', sodium_bin2base64('hello', SODIUM_BASE64_VARIANT_ORIGINAL), "\n";
echo 'named_hex=', sodium_base642bin(string: $enc, id: SODIUM_BASE64_VARIANT_ORIGINAL, ignore: ''), "\n";
echo 'pos_hex=', sodium_base642bin($enc, SODIUM_BASE64_VARIANT_ORIGINAL), "\n";
