<?php

declare(strict_types=1);

/** Bootstrap AOT lint fixture for TYPE_SHIFT_RIGHT (inventory blocker batch 2). */
function bootstrap_shift_right(): int
{
    return 32 >> 2 === 8 ? 0 : 1;
}
