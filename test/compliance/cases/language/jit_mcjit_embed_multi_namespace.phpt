--TEST--
Language: JIT MCJIT embed must not precede bracketed multi-namespace (#28002)
--FILE--
<?php
namespace A {
  function f(){ return 1; }
}
namespace {
  echo \A\f(), PHP_EOL;
}
--EXPECT--
1
