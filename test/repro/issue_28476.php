<?php
/**
 * Repro #28476 — ceil/floor/bindec/hexdec/random_bytes/random_int/password_verify
 * excess/missing argc must throw ArgumentCountError (not LogicException).
 * php-src: ext/standard/math.stub.php, random.stub.php, password.c
 */
try { ceil(); echo "ceil/0:ok\n"; } catch (Throwable $e) { echo 'ceil/0:', get_class($e), ':', $e->getMessage(), "\n"; }
try { ceil(1, 2); echo "ceil/2:ok\n"; } catch (Throwable $e) { echo 'ceil/2:', get_class($e), ':', $e->getMessage(), "\n"; }
try { floor(); echo "floor/0:ok\n"; } catch (Throwable $e) { echo 'floor/0:', get_class($e), ':', $e->getMessage(), "\n"; }
try { floor(1, 2); echo "floor/2:ok\n"; } catch (Throwable $e) { echo 'floor/2:', get_class($e), ':', $e->getMessage(), "\n"; }
try { bindec(); echo "bindec/0:ok\n"; } catch (Throwable $e) { echo 'bindec/0:', get_class($e), ':', $e->getMessage(), "\n"; }
try { bindec('1', 'x'); echo "bindec/2:ok\n"; } catch (Throwable $e) { echo 'bindec/2:', get_class($e), ':', $e->getMessage(), "\n"; }
try { hexdec(); echo "hexdec/0:ok\n"; } catch (Throwable $e) { echo 'hexdec/0:', get_class($e), ':', $e->getMessage(), "\n"; }
try { hexdec('a', 'x'); echo "hexdec/2:ok\n"; } catch (Throwable $e) { echo 'hexdec/2:', get_class($e), ':', $e->getMessage(), "\n"; }
try { random_bytes(); echo "random_bytes/0:ok\n"; } catch (Throwable $e) { echo 'random_bytes/0:', get_class($e), ':', $e->getMessage(), "\n"; }
try { random_bytes(1, 2); echo "random_bytes/2:ok\n"; } catch (Throwable $e) { echo 'random_bytes/2:', get_class($e), ':', $e->getMessage(), "\n"; }
try { random_int(); echo "random_int/0:ok\n"; } catch (Throwable $e) { echo 'random_int/0:', get_class($e), ':', $e->getMessage(), "\n"; }
try { random_int(1, 2, 3); echo "random_int/3:ok\n"; } catch (Throwable $e) { echo 'random_int/3:', get_class($e), ':', $e->getMessage(), "\n"; }
try { password_verify(); echo "password_verify/0:ok\n"; } catch (Throwable $e) { echo 'password_verify/0:', get_class($e), ':', $e->getMessage(), "\n"; }
try { password_verify('a', 'b', 'c'); echo "password_verify/3:ok\n"; } catch (Throwable $e) { echo 'password_verify/3:', get_class($e), ':', $e->getMessage(), "\n"; }
echo 'ceil_ok:', (string) ceil(1.5), "\n";
echo 'floor_ok:', (string) floor(1.5), "\n";
echo 'bindec_ok:', (string) bindec('1010'), "\n";
echo 'hexdec_ok:', (string) hexdec('ff'), "\n";
echo 'password_verify_ok:', password_verify('x', '$2y$10$invalid___________________________') ? '1' : '0', "\n";
