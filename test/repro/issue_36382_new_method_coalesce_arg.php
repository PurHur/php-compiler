<?php
declare(strict_types=1);

/**
 * #36382 — spill method + ?? args before `new` (Slim AppFactory::create shape after patch).
 * Inline `new T(self::make(), $o ?? null)` still mis-wires ARG_SEND under php-cfg; temps match Zend.
 */

final class Dep36382NewCoal {}

final class App36382NewCoal
{
    public Dep36382NewCoal $dep;

    public function __construct(Dep36382NewCoal $dep, ?object $o = null)
    {
        $this->dep = $dep;
    }
}

final class Factory36382NewCoal
{
    public static ?object $o = null;

    public static function make(): Dep36382NewCoal
    {
        return new Dep36382NewCoal();
    }

    public static function create(): App36382NewCoal
    {
        $dep = self::make();
        $opt = self::$o ?? null;
        return new App36382NewCoal($dep, $opt);
    }
}

echo "1\n";
$a = Factory36382NewCoal::create();
echo "2\n";
echo get_class($a->dep), "\n";
echo "ok\n";
