<?php
declare(strict_types=1);

try {
    throw new LogicException('x');
} catch (LogicException|TypeError) {
    echo "caught\n";
}
