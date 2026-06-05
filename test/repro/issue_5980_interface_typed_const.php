<?php

interface I {
    public const string X = 'a';
}

class C implements I {}

echo C::X, "\n";

