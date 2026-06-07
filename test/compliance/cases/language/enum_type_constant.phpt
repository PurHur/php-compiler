--TEST--
Language: enum type constants — public const on backed enum resolves (PHP 8.3, #6590)
--FILE--
<?php
enum E: string {
    case A = 'a';
    public const X = 'x';
}
echo E::X, "\n";
echo constant('E::X'), "\n";
echo defined('E::X') ? 'defined' : 'undefined';
echo "\n";
--EXPECT--
x
x
defined
