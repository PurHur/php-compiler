<?php
declare(strict_types=1);

/**
 * #36382 — two `new` with optional array/null defaults must rematerialize per call site.
 */

final class Resp36382
{
    private int $status;

    public function __construct(
        int $status = 200,
        array $headers = [],
        $body = null,
        string $version = '1.1',
        ?string $reason = null
    ) {
        $this->status = $status;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }
}

function make_a_36382(): Resp36382
{
    return new Resp36382(200, [], null, '1.1', '');
}

function make_b_36382(): Resp36382
{
    return new Resp36382(404, [], null, '1.1', 'Not Found');
}

$a = make_a_36382();
$b = make_b_36382();
echo $a->getStatusCode(), '|', $b->getStatusCode(), "\n";
