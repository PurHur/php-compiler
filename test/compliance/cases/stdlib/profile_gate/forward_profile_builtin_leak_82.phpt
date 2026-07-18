--TEST--
stdlib forward-profile builtins — withheld on 8.2 reference profile (#17206)
--FILE--
<?php
$leaked = array_filter(
    ['mb_trim', 'crc32c', 'attribute_exists', 'class_uses_recursive'],
    static fn (string $fn): bool => function_exists($fn)
);
echo [] === $leaked ? "ok\n" : 'fail: '.implode(',', $leaked)."\n";
--EXPECT--
ok
