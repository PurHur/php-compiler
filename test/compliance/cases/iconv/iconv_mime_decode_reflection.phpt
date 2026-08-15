--TEST--
iconv_mime_decode Reflection string/mode/encoding + named encoding (VM, issue #24378)
--FILE--
<?php
$s = '=?UTF-8?B?SGVsbG8=?=';
$r = new ReflectionFunction('iconv_mime_decode');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
echo implode(',', array_map(static fn($p) => $p->getName(), $r->getParameters())), "\n";
foreach ($r->getParameters() as $p) {
    if ($p->getName() === 'encoding') {
        echo 'encoding=', $p->hasType() ? (string) $p->getType() : 'untyped',
            ' allows_null=', $p->allowsNull() ? '1' : '0',
            $p->isOptional() ? ' =opt' : '',
            "\n";
    }
}
echo 'pos=', iconv_mime_decode($s, 0, 'UTF-8'), "\n";
echo 'named=', iconv_mime_decode($s, mode: 0, encoding: 'UTF-8'), "\n";
try {
    iconv_mime_decode($s, charset: 'UTF-8');
    echo "charset_ok\n";
} catch (Throwable $e) {
    echo 'charset=', get_class($e), "\n";
}
$rh = new ReflectionFunction('iconv_mime_decode_headers');
echo 'hdr=', implode(',', array_map(static fn($p) => $p->getName(), $rh->getParameters())), "\n";
?>
--EXPECT--
ret=string|false
string,mode,encoding
encoding=?string allows_null=1 =opt
pos=Hello
named=Hello
charset=Error
hdr=headers,mode,encoding
