<?php
/**
 * #32899 — class static property string concat must persist under AOT.
 *
 * Peer of function-static `$s.='!'` (#32889): `C::$s.='!'` is FETCH + dead CONCAT
 * into the fetch temp; ephemeral alloca must not skip staticPropertyGlobal store.
 */
class C
{
    public static $s = 'hi';
}

function f(): string
{
    C::$s .= '!';

    return C::$s;
}

echo f(), f(), "\n";

C::$s = 'hi';
C::$s = C::$s.'!';
echo C::$s, "\n";
