--TEST--
variadic rest parameter packs positional trailing args (issue #7338)
--FILE--
<?php
function test(int ...$args): void {
    echo count($args), ':', $args[0] ?? 'x', ':', $args[2] ?? 'x', "\n";
}
test(1, 2, 3);
function g(int $a, ...$rest): void {
    echo $a, ':', count($rest), ':', $rest[1] ?? 'x', "\n";
}
g(1, 2, 3);
function fga(): array {
    return func_get_args();
}
function h(int ...$args): void {
    echo count(fga(1, 2, 3)), ':', count($args), "\n";
}
h(4, 5, 6);
--EXPECT--
3:1:3
1:2:3
3:3
