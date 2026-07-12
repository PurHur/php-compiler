<?php

declare(strict_types=1);

$out = [];
parse_str('a=1;b=2', $out, separator: ';');
if ($out !== ['a' => '1', 'b' => '2']) {
    fwrite(STDERR, 'unexpected: '.var_export($out, true)."\n");
    exit(1);
}
echo "ok\n";
