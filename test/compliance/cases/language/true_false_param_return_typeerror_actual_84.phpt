--TEST--
Language: true/false param+return TypeError actual is true/false on PROFILE≥8.4 (#31160, GH-8385)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
function f(true $x) { return $x; }
try { f(false); } catch (Throwable $e) { echo preg_replace('/, called in .*$/', '', $e->getMessage()), "\n"; }
function g(): true { return false; }
try { g(); } catch (Throwable $e) { echo preg_replace('/, called in .*$/', '', $e->getMessage()), "\n"; }
function h(false $x) {}
try { h(true); } catch (Throwable $e) { echo preg_replace('/, called in .*$/', '', $e->getMessage()), "\n"; }
function i(true $x) {}
try { i(0); } catch (Throwable $e) { echo preg_replace('/, called in .*$/', '', $e->getMessage()), "\n"; }
try { i('1'); } catch (Throwable $e) { echo preg_replace('/, called in .*$/', '', $e->getMessage()), "\n"; }
?>
--EXPECT--
f(): Argument #1 ($x) must be of type true, false given
g(): Return value must be of type true, false returned
h(): Argument #1 ($x) must be of type false, true given
i(): Argument #1 ($x) must be of type true, int given
i(): Argument #1 ($x) must be of type true, string given
