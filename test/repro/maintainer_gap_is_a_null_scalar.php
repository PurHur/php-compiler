<?php

declare(strict_types=1);

// Issue #10873 — is_a() null/scalar subject returns false on Zend, not TypeError.

$subjects = [null, false, true, 0, 1.5, []];
foreach ($subjects as $subject) {
    $result = is_a($subject, 'stdClass');
    echo gettype($subject), '=', var_export($result, true), "\n";
}

// Regression guard for #4853 — string subject + allow_string=false stays false.
var_export(is_a('stdClass', 'stdClass'));
echo "\n";
