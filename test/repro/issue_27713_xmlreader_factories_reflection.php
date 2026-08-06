<?php
declare(strict_types=1);

// #27713 — XMLReader::fromString/fromUri/fromStream Reflection + named args (PROFILE=8.4)
foreach (['fromString', 'fromUri', 'fromStream'] as $m) {
    $rf = new ReflectionMethod(XMLReader::class, $m);
    $parts = [];
    foreach ($rf->getParameters() as $p) {
        $parts[] = $p->getName()
            .':'
            .($p->hasType() ? (string) $p->getType() : '-')
            .':'.($p->isOptional() ? 'OPT' : 'REQ');
    }
    echo $m, ' arity=', $rf->getNumberOfParameters(),
        ' req=', $rf->getNumberOfRequiredParameters(),
        ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none',
        ' [', implode(',', $parts), ']', PHP_EOL;
}

$r = XMLReader::fromString(source: '<?xml version="1.0"?><a/>');
echo 'named_source=', $r instanceof XMLReader ? '1' : '0', PHP_EOL;

$tmp = tempnam(sys_get_temp_dir(), 'xr');
file_put_contents($tmp, '<?xml version="1.0"?><b/>');
$h = fopen($tmp, 'r');
$r2 = XMLReader::fromStream(stream: $h, documentUri: 'file://'.$tmp);
echo 'named_stream=', $r2 instanceof XMLReader ? '1' : '0', PHP_EOL;
fclose($h);
@unlink($tmp);
