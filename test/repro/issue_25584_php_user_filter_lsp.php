<?php

declare(strict_types=1);

// #25584 — php_user_filter Reflection + subclass LSP vs Zend streamsfuncs.stub.php
$r = new ReflectionClass('php_user_filter');
foreach ($r->getMethods() as $m) {
    $bits = [$m->getName(), 'params='.$m->getNumberOfParameters()];
    foreach ($m->getParameters() as $p) {
        $bits[] = ($p->isPassedByReference() ? '&' : '').$p->getName()
            .($p->hasType() ? ':'.$p->getType() : '');
    }
    echo implode(' ', $bits), "\n";
}

class UF extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $bucket->data = strtoupper($bucket->data);
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}

var_export(stream_filter_register('uf.upper', UF::class));
echo "\n";
$fp = fopen('php://memory', 'w+');
stream_filter_append($fp, 'uf.upper');
fwrite($fp, "hi\n");
rewind($fp);
echo stream_get_contents($fp);
echo "ok\n";
