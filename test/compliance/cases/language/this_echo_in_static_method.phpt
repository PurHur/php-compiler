--TEST--
Language: echo/print $this in static method throws Error (#31901)
--FILE--
<?php
error_reporting(E_ALL);

class CThisEchoStatic {
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

    public static function issetThis(): void
    {
        var_dump(isset($this));
    }

    public function instanceEcho(): void
    {
        echo $this;
    }
}

echo 'varexport: ';
try {
    CThisEchoStatic::varExportThis();
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo 'echo: ';
try {
    CThisEchoStatic::echoThis();
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo 'print: ';
try {
    CThisEchoStatic::printThis();
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo 'isset: ';
CThisEchoStatic::issetThis();

echo 'instance: ';
(new CThisEchoStatic())->instanceEcho();
echo "\n";
--EXPECT--
varexport: Error: Using $this when not in object context
echo: Error: Using $this when not in object context
print: Error: Using $this when not in object context
isset: bool(false)
instance: C
