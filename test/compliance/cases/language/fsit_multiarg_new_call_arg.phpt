--TEST--
language: multi-arg new FilesystemIterator as 2nd call arg (issue #21957)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_fsit_flags_21957';
if (!is_dir($dir)) {
    mkdir($dir);
}
file_put_contents($dir . '/a.txt', 'x');
function take2($label, $o) {
    echo $label, '=', get_debug_type($o);
    if (!is_object($o)) {
        echo ' value=', var_export($o, true);
    }
    echo "\n";
}
$it = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
take2('assigned_two', $it);
take2('inline_two', new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS));
take2('inline_one', new FilesystemIterator($dir));
take2('inline_rdi', new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
take2('inline_ao', new ArrayObject([1], ArrayObject::ARRAY_AS_PROPS));
--EXPECT--
assigned_two=FilesystemIterator
inline_two=FilesystemIterator
inline_one=FilesystemIterator
inline_rdi=RecursiveDirectoryIterator
inline_ao=ArrayObject
