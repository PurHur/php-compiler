<?php
// Compile-only (#5644): array_udiff family registers and lowers VM-only call sites for AOT.
$cmp = static fn ($a, $b) => $a <=> $b;
$keycmp = static fn ($a, $b) => strcmp((string) $a, (string) $b);
array_udiff([1, 2], [2, 3], $cmp);
array_uintersect([1, 2, 3], [2, 3, 4], $cmp);
array_udiff_assoc(['a' => 1, 'b' => 2], ['a' => 1], $keycmp);
array_uintersect_assoc(['a' => 1, 'b' => 2], ['a' => 1], $keycmp);
array_udiff_uassoc(['a' => 1], ['a' => 1], $cmp, $keycmp);
array_uintersect_uassoc(['a' => 1, 'b' => 2], ['a' => 1], $cmp, $keycmp);
array_diff_uassoc(['a' => 1, 'b' => 2], ['a' => 2], $cmp);
array_intersect_uassoc(['a' => 1, 'b' => 2], ['a' => 1], $cmp);
array_diff_ukey(['a' => 1, 'b' => 2], ['A' => 3], 'strcasecmp');
