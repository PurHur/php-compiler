--TEST--
stdlib abs/ceil/floor/round/sqrt JIT — backed enum case TypeError (#5613)
--FILE--
<?php
enum E: int { case N = 5; }
try { abs(E::N); echo "abs uncaught\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { ceil(E::N); echo "ceil uncaught\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { floor(E::N); echo "floor uncaught\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { round(E::N); echo "round uncaught\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { sqrt(E::N); echo "sqrt uncaught\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
--EXPECT--
abs(): Argument #1 ($num) must be of type int|float, E given
ceil(): Argument #1 ($num) must be of type int|float, E given
floor(): Argument #1 ($num) must be of type int|float, E given
round(): Argument #1 ($num) must be of type int|float, E given
sqrt(): Argument #1 ($num) must be of type float, E given
