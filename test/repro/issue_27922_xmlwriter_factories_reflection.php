<?php
declare(strict_types=1);

// #27922 — XMLWriter::toMemory/toUri/toStream Reflection + named args (PROFILE=8.4)
foreach (['toMemory', 'toUri', 'toStream'] as $m) {
    $rf = new ReflectionMethod(XMLWriter::class, $m);
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
        ' static=', $rf->isStatic() ? '1' : '0',
        ' [', implode(',', $parts), ']', PHP_EOL;
}

$path = sys_get_temp_dir().'/phpc_xw_27922_'.uniqid().'.xml';
try {
    XMLWriter::toUri(uri: $path);
    echo "named_uri_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
@unlink($path);

$h = fopen('php://memory', 'w+');
try {
    XMLWriter::toStream(stream: $h);
    echo "named_stream_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
fclose($h);
