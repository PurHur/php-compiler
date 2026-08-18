<?php
/** Repro for #31967 — interface constant via self:: in a const expression. */
interface I {
    const X = 20;
}
class C implements I {
    const Y = self::X;
}
echo C::Y;
