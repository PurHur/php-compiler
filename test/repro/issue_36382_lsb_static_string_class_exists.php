<?php
/**
 * #36382 — LSB typed static string + class_exists for a class in the same binary.
 * Mirrors Slim NyholmPsr17Factory::isServerRequestCreatorAvailable().
 */
abstract class BaseFac36382
{
    protected static string $creatorClass;
    protected static string $creatorMethod;

    public static function available(): bool
    {
        $c = static::$creatorClass;
        $m = static::$creatorMethod;
        echo 'class=['.$c.'] method=['.$m.'] ';
        echo 'class_truthy='.($c ? '1' : '0').' ';
        echo 'method_truthy='.($m ? '1' : '0').' ';
        $ce = class_exists($c);
        echo 'class_exists='.($ce ? '1' : '0')."\n";
        return $c && $m && $ce;
    }
}

final class Target36382
{
}

final class NyholmFac36382 extends BaseFac36382
{
    protected static string $creatorClass = 'Target36382';
    protected static string $creatorMethod = 'fromGlobals';

    public static function availableChild(): bool
    {
        return parent::available() && class_exists(static::$creatorClass);
    }
}

echo NyholmFac36382::available() ? "AVAIL\n" : "NO\n";
echo class_exists('Target36382') ? "DIRECT_CE=1\n" : "DIRECT_CE=0\n";
echo NyholmFac36382::availableChild() ? "CHILD_OK\n" : "CHILD_NO\n";
