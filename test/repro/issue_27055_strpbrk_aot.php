<?php
// #27055 — AOT strpbrk must match Zend/VM (avoid echo ternary on string|false; that is a separate AOT gap).
echo strpbrk('hello', 'aeiou'), PHP_EOL;
echo strpbrk('abc-def-ghi', '-'), PHP_EOL;
$miss = strpbrk('xyz', 'aeiou');
echo ($miss === false ? 'false' : 'hit'), PHP_EOL;
