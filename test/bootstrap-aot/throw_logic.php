<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint fixture for Terminal_Throw / TYPE_THROW (issue #57).
 */
class BootstrapThrowMarker
{
}

function bootstrap_throw_logic(): void
{
    throw new BootstrapThrowMarker();
}
