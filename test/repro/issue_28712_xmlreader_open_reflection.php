<?php
declare(strict_types=1);

// #28712 — XMLReader::open/XML Reflection encoding ?string=null; no invented return type
foreach (['open', 'XML'] as $m) {
    $rf = new ReflectionMethod(XMLReader::class, $m);
    $parts = [];
    foreach ($rf->getParameters() as $p) {
        $def = '<none>';
        if ($p->isDefaultValueAvailable()) {
            $def = var_export($p->getDefaultValue(), true);
        }
        $parts[] = $p->getName()
            .':'
            .($p->hasType() ? (string) $p->getType() : '-')
            .':'.($p->isOptional() ? 'OPT' : 'REQ')
            .':def='.$def;
    }
    echo $m, ' arity=', $rf->getNumberOfParameters(),
        ' req=', $rf->getNumberOfRequiredParameters(),
        ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none',
        ' [', implode(',', $parts), ']', PHP_EOL;
}

$tmp = tempnam(sys_get_temp_dir(), 'xr');
file_put_contents($tmp, '<?xml version="1.0"?><a/>');
$r = XMLReader::open(uri: $tmp, encoding: null);
echo 'named_open_null_enc=', $r instanceof XMLReader ? '1' : '0', PHP_EOL;
@unlink($tmp);
