<?php

declare(strict_types=1);

$s = "\xB1\x31";
$encoded = json_encode($s, 2097152);
$hex = bin2hex((string) $encoded);
$ok = '"\ufffd1"' === $encoded && '225c75666666643122' === $hex;
echo $ok ? "ok\n" : "fail encoded={$encoded} hex={$hex}\n";
