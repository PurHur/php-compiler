<?php
declare(strict_types=1);

foreach ([DateTime::class, DateTimeImmutable::class] as $c) {
    echo '== ', $c, " ==\n";
    $r = new ReflectionMethod($c, 'createFromFormat');
    foreach ($r->getParameters() as $p) {
        echo $p->getName(),
            ' type=', $p->hasType() ? (string) $p->getType() : '-',
            ' null=', ($p->hasType() && $p->getType()->allowsNull()) ? '1' : '0',
            ' def=', $p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-',
            "\n";
    }
    $dt = $c::createFromFormat(format: 'Y-m-d', datetime: '2024-01-02');
    echo 'named=', $dt instanceof $c ? $dt->format('Y-m-d') : 'fail', "\n";
}
