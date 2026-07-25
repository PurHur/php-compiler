<?php

declare(strict_types=1);

/**
 * Late static methods on a large class must survive AOT registration (#22717, #22642).
 *
 * The r13 gen-0 spine refresh died after ~4.6h on the spine's top-level anchor
 * `VmMbstring::encodeMimeheader()` with "Call to undefined static method" — the method exists
 * in source, but late methods on this 3.4k-line / 158-static-method class were not registered
 * during honest full-spine AOT.
 *
 * If the registration limit is per-class rather than spine-scale, this fixture reproduces it in
 * minutes instead of hours:
 *
 *   php bin/compile.php -o /tmp/i22717 test/repro/issue_22717_vmmbstring_late_static_method_aot.php
 *   /tmp/i22717
 *
 * Expected: "mimeheader roundtrip ok".
 */

require_once __DIR__.'/../../ext/mbstring/VmMbstring.php';

$sample = 'Hello 世界';
$encoded = \PHPCompiler\ext\mbstring\VmMbstring::encodeMimeheader($sample, 'UTF-8', true, "\r\n", 0);
$decoded = \PHPCompiler\ext\mbstring\VmMbstring::decodeMimeheader($encoded);

if ($decoded === $sample) {
    echo "mimeheader roundtrip ok\n";
} else {
    echo 'mimeheader roundtrip MISMATCH: '.var_export($decoded, true)."\n";
}
