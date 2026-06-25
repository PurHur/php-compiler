<?php

declare(strict_types=1);

/**
 * Maintainer repro: forward_static_call_array() global scope builtin (#11667).
 */

echo forward_static_call_array('strlen', ['abc']), "\n";
