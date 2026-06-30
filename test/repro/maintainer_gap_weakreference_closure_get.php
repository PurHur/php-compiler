<?php

declare(strict_types=1);

$alive = (function (): bool {
    $o = new stdClass();
    $wr = WeakReference::create($o);

    return $wr->get() === $o;
})();

if (!$alive) {
    fwrite(STDERR, "WeakReference::get() inside closure did not return referent\n");
    exit(1);
}
echo "ok\n";
