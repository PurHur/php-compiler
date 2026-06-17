<?php

enum E: int { case A = 1; }

echo (E::A?->name ?? 'NULL'), "\n";
echo (E::A?->value ?? 'NULL'), "\n";
echo (E::A->name ?? 'NULL'), "\n";
echo (E::A->value ?? 'NULL'), "\n";

