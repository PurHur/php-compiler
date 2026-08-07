<?php
declare(strict_types=1);

// Repro #28528 / #22899 — isSensitive* absent on every php-src-strict profile
// (including PHP_COMPILER_PROFILE=8.4/8.5). #[\SensitiveParameter] redaction is separate.
foreach (['isSensitive', 'isSensitiveParameter'] as $m) {
    echo $m, '=', method_exists(ReflectionParameter::class, $m) ? 'y' : 'n', "\n";
}
