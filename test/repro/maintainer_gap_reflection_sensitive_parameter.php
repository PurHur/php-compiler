<?php

declare(strict_types=1);

/**
 * #28528 — isSensitive* ReflectionParameter methods are phantoms; expect absence.
 * #[\SensitiveParameter] redaction is separate (SensitiveParamSupport).
 */
$fail = 0;

foreach (['isSensitive', 'isSensitiveParameter'] as $m) {
    if (method_exists(ReflectionParameter::class, $m)) {
        echo "FAIL method_exists {$m}\n";
        ++$fail;
    }
}

exit($fail === 0 ? 0 : 1);
