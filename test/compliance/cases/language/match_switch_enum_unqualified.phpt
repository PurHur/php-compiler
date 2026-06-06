--TEST--
Language: match/switch unqualified enum case labels (#6947, zend_compile.c)
--FILE--
<?php
enum Status { case Pending; case Done; }
$s = Status::Pending;
echo match ($s) {
    Pending => 1,
    Done => 2,
}, "\n";
switch ($s) {
    case Pending:
        echo "done\n";
        break;
}
enum E { case A; case B;
    public function pick(): E {
        return match ($this) {
            A => B,
            B => A,
        };
    }
}
echo E::A->pick()->name, "\n";
--EXPECT--
1
done
B
