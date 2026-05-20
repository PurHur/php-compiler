--TEST--
Language: null coalescing operator (??)
--FILE--
<?php
$b = null;
echo $b ?? 'Guest', "\n";

$c = 'Alice';
echo $c ?? 'Guest', "\n";

$items = ['page' => 'home'];
echo $items['page'] ?? 'index', "\n";
echo $items['missing'] ?? 'index', "\n";

echo 0 ?? 'zero', "\n";
echo '' ?? 'empty', "\n";
--EXPECT--
Guest
Alice
home
index
0

--FILE--
<?php
function returnsNull(): ?string {
    return null;
}
echo returnsNull() ?? 'fallback', "\n";
--EXPECT--
fallback
