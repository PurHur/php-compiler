<?php
// repro #23230 — Soap\Url / Soap\Sdl on PROFILE=8.4+ (ext/soap/soap.stub.php)
declare(strict_types=1);

if (!class_exists('SoapClient', false)) {
    fwrite(STDERR, "SoapClient missing (need PROFILE>=8.4 or host soap)\n");
    exit(1);
}

foreach (['Soap\\Url', 'Soap\\Sdl'] as $name) {
    if (!class_exists($name, false)) {
        fwrite(STDERR, "missing $name\n");
        exit(1);
    }
    $r = new ReflectionClass($name);
    if (!$r->isFinal() || !$r->isInternal()) {
        fwrite(STDERR, "$name final=".((int) $r->isFinal())." internal=".((int) $r->isInternal())."\n");
        exit(1);
    }
    try {
        new $name();
        fwrite(STDERR, "$name construct allowed\n");
        exit(1);
    } catch (Error $e) {
        if (!str_contains($e->getMessage(), 'Cannot directly construct')) {
            fwrite(STDERR, "$name bad ctor: ".$e->getMessage()."\n");
            exit(1);
        }
    }
}

echo "ok\n";
