--TEST--
Language: enum bare const without visibility resolves like public const (#6878, zend_compile.c)
--FILE--
<?php
enum E: string {
    case A = 'a';
    const X = 1;
}
enum U {
    case A;
    const Y = 2;
}
echo E::X, "\n";
echo U::Y, "\n";
echo constant('E::X'), "\n";
echo defined('E::X') ? 'defined' : 'undefined';
echo "\n";
--EXPECT--
1
2
1
defined
