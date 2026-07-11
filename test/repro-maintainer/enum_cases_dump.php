<?php

declare(strict_types=1);

/**
 * Issue #4739 — var_dump(Enum::cases()) must print enum(Class::Case) not unknown().
 *
 * @see Zend/zend.c zend_enum_get_case / debug_zval_dump enum branch
 */

enum Color: string
{
    case Red = 'red';
    case Green = 'green';
}

enum Size
{
    case S;
    case M;
}

var_dump(Color::cases());
var_dump(Size::cases());
echo Color::Red->name, "\n";
