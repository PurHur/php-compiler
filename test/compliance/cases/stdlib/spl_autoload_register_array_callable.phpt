--TEST--
stdlib spl_autoload_register() array static callable (issue #4744, ext/spl/php_spl.c)
--FILE--
<?php
class L { public static function load(string $c): void { if ($c === 'X') { class X { public function id(): int { return 3; } } } } }
spl_autoload_register([L::class, 'load']);
echo (new X())->id(), "\n";
--EXPECT--
3
