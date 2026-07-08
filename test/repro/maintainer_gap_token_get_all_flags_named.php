<?php

declare(strict_types=1);

$src = '<?php echo 1;';

$tokens = token_get_all($src, flags: TOKEN_PARSE);
if (5 !== count($tokens)) {
    echo 'fail: expected 5 tokens, got '.count($tokens)."\n";
    exit(1);
}

echo "ok\n";
