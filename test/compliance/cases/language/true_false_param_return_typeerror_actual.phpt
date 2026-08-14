--TEST--
Language: true/false param+return TypeError actual is bool on default 8.2 profile (#31160, zend_execute_API.c)
--SKIPIF--
<?php
$raw = getenv('PHP_COMPILER_PROFILE');
if (is_string($raw) && '' !== trim($raw)) {
    $v = trim($raw);
    if (preg_match('/^\d+\.\d+$/', $v)) {
        $v .= '.0';
    }
    if (version_compare($v, '8.4.0', '>=')) {
        die('skip GH-8385 true/false TypeError actuals on PHP 8.4+ forward profile');
    }
}
?>
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
f(): Argument #1 ($x) must be of type true, bool given
g(): Return value must be of type true, bool returned
h(): Argument #1 ($x) must be of type false, bool given
i(): Argument #1 ($x) must be of type true, int given
i(): Argument #1 ($x) must be of type true, string given
