<?php

declare(strict_types=1);

/**
 * AOT serialize SplFixedArray / ArrayObject non-empty (#34491).
 */
echo serialize(SplFixedArray::fromArray([1, 2])), "\n";
echo serialize(SplFixedArray::fromArray([1.5, true])), "\n";
echo serialize(new ArrayObject([1, 2])), "\n";
echo serialize(new SplFixedArray(0)), "\n";
