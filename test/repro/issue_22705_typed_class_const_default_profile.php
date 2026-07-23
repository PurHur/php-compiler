<?php
// Issue #22705 — typed class constants must parse-error on default profile (phpversion 8.2.31).
echo 'ver=', phpversion(), "\n";
class C {
    public const string NAME = 'x';
}
echo C::NAME, "\n";
