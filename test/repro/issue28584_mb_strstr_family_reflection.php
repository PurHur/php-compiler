<?php

declare(strict_types=1);

/**
 * #28584 — mb_strstr/mb_stristr/mb_strrchr/mb_strrichr Reflection must match
 * php-src mbstring.stub.php: string|false return + ?string $encoding = null.
 */
$fns = ['mb_strstr', 'mb_stristr', 'mb_strrchr', 'mb_strrichr'];
foreach ($fns as $f) {
    $r = new ReflectionFunction($f);
    $ret = $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE';
    if ('string|false' !== $ret) {
        fwrite(STDERR, "fail: {$f} ret={$ret}\n");
        exit(1);
    }
    $enc = null;
    foreach ($r->getParameters() as $p) {
        if ('encoding' === $p->getName()) {
            $enc = $p;
            break;
        }
    }
    if (null === $enc || '?string' !== (string) $enc->getType()) {
        fwrite(STDERR, "fail: {$f} encoding type\n");
        exit(1);
    }
    if (!$enc->isOptional() || !$enc->allowsNull()) {
        fwrite(STDERR, "fail: {$f} encoding nullability\n");
        exit(1);
    }
    if (!$enc->isDefaultValueAvailable() || null !== $enc->getDefaultValue()) {
        fwrite(STDERR, "fail: {$f} encoding default\n");
        exit(1);
    }
    echo $f, ':ok', "\n";
}

if (false !== mb_strstr('abc', 'z')) {
    fwrite(STDERR, "fail: miss path\n");
    exit(1);
}
echo "miss:ok\n";

$hit = mb_strstr('abc', 'b', encoding: null);
if ('bc' !== $hit) {
    fwrite(STDERR, 'fail: encoding:null hit got '.var_export($hit, true)."\n");
    exit(1);
}
echo "encoding_null:ok\n";
echo "ok\n";
