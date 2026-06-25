<?php

declare(strict_types=1);

$methods = get_class_methods(Iterator::class);
sort($methods);
$expected = ['current', 'key', 'next', 'rewind', 'valid'];
echo $methods === $expected ? "ok\n" : 'got='.var_export($methods, true)."\n";
exit($methods === $expected ? 0 : 1);
