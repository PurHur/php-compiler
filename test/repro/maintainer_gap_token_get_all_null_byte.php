<?php

declare(strict_types=1);

$src = "<?php \$\0 = 1;";
$tokens = token_get_all($src);

if (!isset($tokens[1]) || '$' !== $tokens[1]) {
    $got = isset($tokens[1]) && is_array($tokens[1])
        ? 'T_' . ($tokens[1][0] ?? '?')
        : var_export($tokens[1] ?? null, true);
    fwrite(STDERR, "second token: expected literal '\$', got {$got}\n");
    exit(1);
}

if (!isset($tokens[2]) || !is_array($tokens[2]) || T_BAD_CHARACTER !== $tokens[2][0]) {
    fwrite(STDERR, "third token: expected T_BAD_CHARACTER\n");
    exit(1);
}

echo "ok\n";
