<?php
declare(strict_types=1);

/**
 * #36382 — Slim RouteCollector shape: required objects + trailing nullable iface/string
 * defaults omitted at the call site. After #36871 RouteCollector patch lands past
 * instanceof defaults; AOT still SIGSEGV returning from `new` (post-ctor / optional-arg delref).
 */

interface Strat36382
{
}

final class StratImpl36382 implements Strat36382
{
}

final class RF36382
{
}

final class CR36382
{
}

final class RC36382
{
    public RF36382 $rf;
    public CR36382 $cr;
    public ?object $container;
    public Strat36382 $strat;
    public string $cache;

    public function __construct(
        RF36382 $rf,
        CR36382 $cr,
        ?object $container = null,
        ?Strat36382 $strat = null,
        ?string $cacheFile = null
    ) {
        $this->rf = $rf;
        $this->cr = $cr;
        $this->container = $container;
        $this->strat = $strat instanceof Strat36382 ? $strat : new StratImpl36382();
        $this->cache = $cacheFile ?? '';
    }
}

$rf = new RF36382();
$cr = new CR36382();
$rc = new RC36382($rf, $cr, null);
echo 'RC_ok:', ($rc->strat instanceof StratImpl36382 ? '1' : '0'), ':', $rc->cache, "\n";
