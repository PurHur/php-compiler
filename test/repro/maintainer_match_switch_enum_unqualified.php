<?php
/** Repro for #6947 — unqualified enum case labels in match/switch (zend_compile.c). */
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
    default:
        echo "other\n";
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
