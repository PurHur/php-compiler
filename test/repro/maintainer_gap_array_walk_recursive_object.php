<?php

declare(strict_types=1);

$o = (object) ['a' => ['x' => 1]];
array_walk_recursive($o, static function (&$v): void {
    $v++;
});
var_export($o);
echo "\n";
