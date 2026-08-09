--TEST--
Language: __toString throw during ==/</<=> aborts try body (#29534)
--FILE--
<?php
error_reporting(E_ALL);
class T {
    public function __toString() {
        throw new Exception('t');
    }
}
function cmp_eq() { return (new T()) == 'x'; }
function cmp_lt() { return (new T()) < 'x'; }
function cmp_sp() { return (new T()) <=> 'x'; }

try {
    var_export((new T()) == 'x');
    echo "AFTER_DIRECT\n";
} catch (Throwable $e) {
    echo 'caught_direct:', $e->getMessage(), "\n";
}
try {
    $r = cmp_eq();
    echo "AFTER_EQ\n";
} catch (Throwable $e) {
    echo 'caught_eq:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $r = cmp_lt();
    echo "AFTER_LT\n";
} catch (Throwable $e) {
    echo 'caught_lt:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $r = cmp_sp();
    echo "AFTER_SP\n";
} catch (Throwable $e) {
    echo 'caught_sp:', get_class($e), ':', $e->getMessage(), "\n";
}
$fn = fn() => (new T()) == 'x';
try {
    $r = $fn();
    echo "AFTER_ARROW\n";
} catch (Throwable $e) {
    echo 'caught_arrow:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "DONE\n";
--EXPECT--
caught_direct:t
caught_eq:Exception:t
caught_lt:Exception:t
caught_sp:Exception:t
caught_arrow:Exception:t
DONE
