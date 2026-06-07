--TEST--
Stdlib: chdir() JIT — enum case path operand must TypeError (#7206)
--FILE--
<?php
enum P: string { case Root = '/tmp'; }
try {
    chdir(P::Root);
    echo "no_exception\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: chdir(): Argument #1 ($directory) must be of type string, P given
