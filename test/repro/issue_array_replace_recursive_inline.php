<?php

declare(strict_types=1);

// Issue #10662 — inline nested array literals as first array_replace_recursive() operand.
var_export(array_replace_recursive(['a' => ['b' => 1]], ['a' => ['c' => 2]]));
echo "\n";
