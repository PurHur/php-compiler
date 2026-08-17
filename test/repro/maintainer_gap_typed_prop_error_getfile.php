<?php
// Typed-property / unset-static Errors must stamp user getFile()/getLine() like Zend.
function show(string $label, Throwable $e): void
{
    $f = $e->getFile();
    echo $label,
        '|file=', ($f === '' || $f === null) ? '(empty)' : basename($f),
        '|line=', $e->getLine(),
        '|', get_class($e),
        "\n";
}

class T1 { public int $x; }
try { echo (new T1)->x; } catch (Throwable $e) { show('typed-read', $e); }

class T2 { public static int $x; }
try { echo T2::$x; } catch (Throwable $e) { show('typed-static-read', $e); }

class T3 { public static $x = 1; }
try { unset(T3::$x); } catch (Throwable $e) { show('unset-static', $e); }

class T8 { public int $x; }
$o8 = new T8;
try { $r = &$o8->x; echo "byref-ok\n"; } catch (Throwable $e) { show('byref-typed', $e); }

// Control: readonly write should already stamp user file (must stay green).
class T5 {
    public readonly int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$o5 = new T5(1);
try { $o5->x = 2; } catch (Throwable $e) { show('readonly-write', $e); }
