<?php

declare(strict_types=1);

enum ES: string
{
    case A = 'a';
}
enum EI: int
{
    case A = 1;
}
enum PU
{
    case A;
}

print_r(ES::A);
print_r(EI::A);
print_r(PU::A);

exit(0);
