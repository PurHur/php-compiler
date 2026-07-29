--TEST--
Language: #[\Override] still fatals after class_exists('Override') — CFG successor decls (#24790)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo "Override_class=", class_exists("Override", false) ? "1" : "0", "\n";
class A { public function f(): int { return 1; } }
class C extends A {
  #[\Override]
  public function g(): int { return 3; }
}
echo "C_ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: C::g() has #[\Override] attribute, but no matching parent method exists
