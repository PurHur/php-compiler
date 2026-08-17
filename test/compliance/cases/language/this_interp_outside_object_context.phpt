--TEST--
Language: "$this" / "{$this}" / heredoc outside object context throws Error (#31728)
--FILE--
<?php
error_reporting(E_ALL);

echo 'dq: ';
try {
    $s = "$this";
    echo 'OK ', var_export($s, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo 'curly: ';
try {
    $s = "{$this}";
    echo 'OK ', var_export($s, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo 'heredoc: ';
try {
    $s = <<<TXT
{$this}
TXT;
    echo 'OK ', var_export($s, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

function f_this_interp(): string {
    return "$this";
}
echo 'fn: ';
try {
    $r = f_this_interp();
    echo 'OK ', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

class CThisInterp {
    public function __toString(): string {
        return 'C';
    }
    public function s(): string {
        return "$this";
    }
}
echo 'method: ', (new CThisInterp())->s(), "\n";
--EXPECT--
dq: Error: Using $this when not in object context
curly: Error: Using $this when not in object context
heredoc: Error: Using $this when not in object context
fn: Error: Using $this when not in object context
method: C
