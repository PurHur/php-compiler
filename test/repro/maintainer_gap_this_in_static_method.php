<?php
/**
 * Maintainer gap #31901: echo/print $this in a static method is silent.
 * Zend: Error "Using $this when not in object context" (catchable); no UNREACHABLE.
 * VM/JIT: empty write, then UNREACHABLE (exit 0).
 *
 * Control: var_export($this) already Errors on VM.
 */
error_reporting(E_ALL);

class CThisStaticEcho {
    public function __toString(): string
    {
        return 'C';
    }

    public static function echoThis(): void
    {
        echo $this;
        echo 'UNREACHABLE_ECHO';
    }

    public static function printThis(): void
    {
        print $this;
        echo 'UNREACHABLE_PRINT';
    }

    public static function varExportThis(): void
    {
        var_export($this);
        echo 'UNREACHABLE_VAREXPORT';
    }

    public function instanceEcho(): void
    {
        echo $this;
    }
}

echo 'varexport: ';
try {
    CThisStaticEcho::varExportThis();
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo 'echo: ';
try {
    CThisStaticEcho::echoThis();
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo 'print: ';
try {
    CThisStaticEcho::printThis();
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo 'isset: ';
class CThisStaticIsset {
    public static function f(): void
    {
        var_dump(isset($this));
    }
}
CThisStaticIsset::f();

echo 'instance: ';
(new CThisStaticEcho())->instanceEcho();
echo "\n";
