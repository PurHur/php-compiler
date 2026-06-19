<?php
declare(strict_types=1);

enum G: string { case X = 'x'; }

class C {
    public static G $g = G::X;
}

echo C::$g->name, "\n";
