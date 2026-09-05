<?php
declare(strict_types=1);

/**
 * #36382 — Slim AppFactory::create / App::__construct shape:
 * nullable optional args omitted; ?? defaults; nested NEW in parent ctor;
 * RouteRunner receives $this before App ctor finishes.
 */

interface Handler36382
{
}

final class Kernel36382 implements Handler36382
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

    public function __construct(RF36382 $rf, CR36382 $cr, ?object $container = null)
    {
        $this->rf = $rf;
        $this->cr = $cr;
    }
}

final class RR36382
{
    public RC36382 $rc;

    public function __construct(RC36382 $rc)
    {
        $this->rc = $rc;
    }
}

final class Runner36382 implements Handler36382
{
    public RR36382 $rr;
    public object $proxy;

    public function __construct(RR36382 $rr, object $proxy)
    {
        $this->rr = $rr;
        $this->proxy = $proxy;
    }
}

final class MD36382
{
    public Handler36382 $tip;
    public CR36382 $cr;

    public function __construct(Handler36382 $tip, CR36382 $cr, ?object $container = null)
    {
        $this->tip = $tip;
        $this->cr = $cr;
    }
}

class Proxy36382
{
    public RF36382 $rf;
    public CR36382 $cr;
    public RC36382 $rc;

    public function __construct(
        RF36382 $rf,
        CR36382 $cr,
        ?object $container = null,
        ?RC36382 $rc = null
    ) {
        $this->rf = $rf;
        $this->cr = $cr;
        $this->rc = $rc ?? new RC36382($rf, $cr, $container);
    }
}

final class App36382 extends Proxy36382
{
    public RR36382 $resolver;
    public MD36382 $md;

    public function __construct(
        RF36382 $rf,
        ?object $container = null,
        ?CR36382 $cr = null,
        ?RC36382 $rc = null,
        ?RR36382 $resolver = null,
        ?MD36382 $md = null
    ) {
        $cr = $cr ?? new CR36382();
        parent::__construct($rf, $cr, $container, $rc);
        $this->resolver = $resolver ?? new RR36382($this->rc);
        $runner = new Runner36382($this->resolver, $this);
        if (!$md) {
            $md = new MD36382($runner, $this->cr, $container);
        }
        $this->md = $md;
    }
}

final class Factory36382
{
    public static ?RF36382 $rf = null;

    public static function create(
        ?RF36382 $rf = null,
        ?object $container = null,
        ?CR36382 $cr = null,
        ?RC36382 $rc = null,
        ?RR36382 $resolver = null,
        ?MD36382 $md = null
    ): App36382 {
        self::$rf = $rf ?? self::$rf;
        return new App36382(
            self::$rf ?? new RF36382(),
            $container ?? null,
            $cr ?? null,
            $rc ?? null,
            $resolver ?? null,
            $md ?? null
        );
    }
}

echo "C1\n";
$rf = new RF36382();
echo "C2\n";
Factory36382::$rf = $rf;
echo "C3\n";
$app = Factory36382::create();
echo "C4\n";
echo get_class($app), "\n";
echo 'ok:', ($app->md->tip instanceof Runner36382 ? '1' : '0'), "\n";
// force 1788629786181735234
