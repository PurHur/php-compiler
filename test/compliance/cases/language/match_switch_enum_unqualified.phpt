--TEST--
Language: match/switch unqualified enum case labels (#6947, #16720, zend_compile.c)
--FILE--
<?php
enum Status { case Pending; case Done; }
$s = Status::Pending;
try {
    echo match ($s) {
        Pending => 1,
        Done => 2,
    }, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    switch ($s) {
        case Pending:
            echo "done\n";
            break;
    }
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo match ($s) {
    Status::Pending => 1,
    Status::Done => 2,
}, "\n";
enum E { case A; case B;
    public function pick(): E {
        return match ($this) {
            self::A => self::B,
            self::B => self::A,
        };
    }
}
echo E::A->pick()->name, "\n";
--EXPECT--
Error: Undefined constant "Pending"
Error: Undefined constant "Pending"
1
B
