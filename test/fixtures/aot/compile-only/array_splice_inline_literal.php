<?php
declare(strict_types=1);

// Compile-only smoke: inline literal by-ref Error lowering (#9364).
array_splice([], 0, 0, ['x']);
