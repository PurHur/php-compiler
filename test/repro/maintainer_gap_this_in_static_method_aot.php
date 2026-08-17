<?php
class C {
    public function __toString(): string { return 'C'; }
    public static function g() { echo $this; echo 'UNREACHABLE'; }
    public static function p() { print $this; echo 'UNREACHABLE_PRINT'; }
    public function m() { echo $this; }
}
echo 'echo: ';
try { C::g(); echo "OK\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo 'print: ';
try { C::p(); echo "OK\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo 'instance: ';
(new C())->m();
echo "\n";
