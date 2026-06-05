<?php

interface I {
    public const string X = 'a';
}

class C implements I {
    public const int X = 1;
}

echo "compiled\n";

