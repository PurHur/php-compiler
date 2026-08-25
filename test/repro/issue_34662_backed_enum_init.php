<?php

declare(strict_types=1);

/**
 * #34662 — backed enum case fetch must compile under AOT after #34649 propertyStore box split.
 *
 * Unit enums (no value slot store) already worked; int/string-backed cases sealed __init__.
 */
enum IntE: int
{
    case A = 1;
    case B = 2;
}

enum StrE: string
{
    case X = 'x';
}

echo IntE::A->value, ' ', IntE::B->name, ' ', StrE::X->value, "\n";
