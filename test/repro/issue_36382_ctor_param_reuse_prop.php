<?php
declare(strict_types=1);

/**
 * #36382 — nullable ctor param reused as `$md = new T; $this->md = $md` must keep
 * properties after __construct returns (Slim AppFactory `if (!$middleware)` shape).
 * Frame-end local release must not delref CVs that escaped into `$this`.
 */

interface Handler36382Md
{
}

final class R36382Md implements Handler36382Md
{
}

final class CR36382Md
{
}

final class MD36382Md
{
    public Handler36382Md $tip;
    public CR36382Md $cr;

    public function __construct(Handler36382Md $tip, CR36382Md $cr)
    {
        $this->tip = $tip;
        $this->cr = $cr;
    }
}

final class App36382Md
{
    public MD36382Md $md;
    public CR36382Md $cr;

    public function __construct(?MD36382Md $md = null)
    {
        $this->cr = new CR36382Md();
        if (!$md) {
            $md = new MD36382Md(new R36382Md(), $this->cr);
        }
        $this->md = $md;
    }
}

$o = new App36382Md();
echo get_class($o->md->tip), "\n";
echo 'ok', "\n";
