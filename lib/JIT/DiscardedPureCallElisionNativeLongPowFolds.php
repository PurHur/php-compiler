<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Native-long {@code **} / {@code pow()} compile-time exponent fold helpers (#36387).
 *
 * Extracted from {@see DiscardedPureCallElisionNativeLongFolds} so the gen-0
 * spine gets another TU and the native-long fold file stays under the
 * size-budget ratchet. External call sites keep using
 * {@code DiscardedPureCallElision::…} (trait methods on the hub class).
 *
 * Used via {@code use DiscardedPureCallElisionNativeLongPowFolds;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: Zend/zend_operators.c {@code pow_function} /
 * {@code zend_pow} / {@code mul_function}; ext/standard/math.c
 * {@code PHP_FUNCTION(pow)}.
 */
trait DiscardedPureCallElisionNativeLongPowFolds
{
    /**
     * Typed integer {@code **} / {@code pow()} when the exponent is a
     * compile-time long:
     * - {@code $n ** 0} / {@code pow($n, 0)} → {@code 1} (incl. {@code 0 ** 0})
     * - {@code $n ** 1} / {@code pow($n, 1)} → {@code $n} (identity)
     * - {@code $n ** 2} / {@code pow($n, 2)} → {@code $n * $n} with
     *   {@code llvm.smul.with.overflow} → float promote on overflow (same
     *   shape as typed {@code *} / {@code mul_function})
     * - {@code $n ** 3} / {@code pow($n, 3)} → {@code $n * $n * $n} with
     *   chained smul overflow→float (first {@code n*n}, then {@code ×n})
     * - {@code $n ** 4} / {@code pow($n, 4)} → {@code ($n*$n)*($n*$n)} with
     *   chained smul overflow→float (square, then square-of-square)
     * - {@code $n ** 5} / {@code pow($n, 5)} → {@code ($n*$n*$n)*($n*$n)} with
     *   chained smul overflow→float (cube × square)
     * - {@code $n ** 6} / {@code pow($n, 6)} → {@code ($n*$n*$n)*($n*$n*$n)} with
     *   chained smul overflow→float (cube × cube)
     * - {@code $n ** 7} / {@code pow($n, 7)} → {@code (($n*$n*$n)*($n*$n*$n))*$n}
     *   with chained smul overflow→float (cube × cube × n)
     * - {@code $n ** 8} / {@code pow($n, 8)} → {@code (($n*$n)*($n*$n))*(($n*$n)*($n*$n))}
     *   with chained smul overflow→float (fourth × fourth)
     * - {@code $n ** 9} / {@code pow($n, 9)} → {@code (($n*$n*$n)*($n*$n*$n))*($n*$n*$n)}
     *   with chained smul overflow→float (sixth × cube)
     * - {@code $n ** 10} / {@code pow($n, 10)} → {@code (($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n))}
     *   with chained smul overflow→float (fifth × fifth)
     * - {@code $n ** 11} / {@code pow($n, 11)} → {@code ((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*$n}
     *   with chained smul overflow→float (tenth × n)
     * - {@code $n ** 12} / {@code pow($n, 12)} → {@code (($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n))}
     *   with chained smul overflow→float (sixth × sixth)
     * - {@code $n ** 13} / {@code pow($n, 13)} → {@code ((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*$n}
     *   with chained smul overflow→float (twelfth × n)
     * - {@code $n ** 14} / {@code pow($n, 14)} → {@code (((($n*$n*$n)*($n*$n*$n))*$n)*((($n*$n*$n)*($n*$n*$n))*$n))}
     *   with chained smul overflow→float (seventh × seventh)
     * - {@code $n ** 15} / {@code pow($n, 15)} → {@code ((((($n*$n*$n)*($n*$n*$n))*$n)*((($n*$n*$n)*($n*$n*$n))*$n))*$n)}
     *   with chained smul overflow→float (fourteenth × n)
     * - {@code $n ** 16} / {@code pow($n, 16)} → {@code (((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))}
     *   with chained smul overflow→float (eighth × eighth)
     * - {@code $n ** 17} / {@code pow($n, 17)} → {@code ((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*$n}
     *   with chained smul overflow→float (sixteenth × n)
     * - {@code $n ** 18} / {@code pow($n, 18)} → {@code (((($n*$n*$n)*($n*$n*$n))*($n*$n*$n))*((($n*$n*$n)*($n*$n*$n))*($n*$n*$n)))}
     *   with chained smul overflow→float (ninth × ninth)
     * - {@code $n ** 19} / {@code pow($n, 19)} → {@code ((((($n*$n*$n)*($n*$n*$n))*($n*$n*$n))*((($n*$n*$n)*($n*$n*$n))*($n*$n*$n)))*$n}
     *   with chained smul overflow→float (eighteenth × n)
     * - {@code $n ** 20} / {@code pow($n, 20)} → {@code (((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n))))}
     *   with chained smul overflow→float (tenth × tenth)
     * - {@code $n ** 21} / {@code pow($n, 21)} → {@code ((((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n))))*$n}
     *   with chained smul overflow→float (twentieth × n)
     * - {@code $n ** 22} / {@code pow($n, 22)} → {@code ((((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*$n)*(((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*$n))}
     *   with chained smul overflow→float (eleventh × eleventh)
     * - {@code $n ** 23} / {@code pow($n, 23)} → {@code (((((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*$n)*(((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*$n))*$n}
     *   with chained smul overflow→float (twentysecond × n)
     * - {@code $n ** 24} / {@code pow($n, 24)} → {@code (((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n))))}
     *   with chained smul overflow→float (twelfth × twelfth)
     * - {@code $n ** 25} / {@code pow($n, 25)} → {@code ((((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n))))*$n}
     *   with chained smul overflow→float (twentyfourth × n)
     * - {@code $n ** 26} / {@code pow($n, 26)} → {@code (((((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*$n)*(((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*$n))}
     *   with chained smul overflow→float (thirteenth × thirteenth)
     * - {@code $n ** 27} / {@code pow($n, 27)} → {@code ((((((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*$n)*(((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*$n))*$n}
     *   with chained smul overflow→float (twentysixth × n)
     * - {@code $n ** 28} / {@code pow($n, 28)} → {@code (((((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n))*(((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n)))}
     *   with chained smul overflow→float (fourteenth × fourteenth)
     * - {@code $n ** 29} / {@code pow($n, 29)} → {@code ((((((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n))*(((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n)))*$n}
     *   with chained smul overflow→float (twentyeighth × n)
     * - {@code $n ** 30} / {@code pow($n, 30)} → {@code (((((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n))*$n)*((((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n))*$n)}
     *   with chained smul overflow→float (fifteenth × fifteenth)
     * - {@code $n ** 31} / {@code pow($n, 31)} → {@code ((((((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n))*$n)*((((($n*$n*$n)*($n*$n*$n))*n)*((($n*$n*$n)*($n*$n*$n))*n))*$n))*$n}
     *   with chained smul overflow→float (thirtieth × n)
     * - {@code $n ** 32} / {@code pow($n, 32)} → {@code (((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))))}
     *   with chained smul overflow→float (sixteenth × sixteenth)
     * - {@code $n ** 33} / {@code pow($n, 33)} → {@code ((((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))))*$n}
     *   with chained smul overflow→float (thirtysecond × n)
     * - {@code $n ** 34} / {@code pow($n, 34)} → {@code ((((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*$n)*(((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*$n))}
     *   with chained smul overflow→float (seventeenth × seventeenth)
     * - {@code $n ** 35} / {@code pow($n, 35)} → {@code (((((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*$n)*(((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*$n))*$n}
     *   with chained smul overflow→float (thirtyfourth × n)
     * - {@code $n ** 36} / {@code pow($n, 36)} → {@code ((((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))))*((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n)))))}
     *   with chained smul overflow→float (eighteenth × eighteenth)
     * - {@code $n ** 37} / {@code pow($n, 37)} → {@code (((((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))))*((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n)))))*$n}
     *   with chained smul overflow→float (thirtysixth × n)
     * - {@code $n ** 38} / {@code pow($n, 38)} → {@code ((((((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))))*((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n)))))*$n)*$n}
     *   with chained smul overflow→float (thirtyseventh × n)
     * - {@code $n ** 39} / {@code pow($n, 39)} → {@code (((((((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))))*((((((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n))*(((($n*$n)*$n)*(($n*$n)*$n))*(($n*$n)*$n)))))*$n)*$n)*$n}
     *   with chained smul overflow→float (thirtyeighth × n)
     * - {@code $n ** 40} / {@code pow($n, 40)} → {@code (((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))}
     *   with chained smul overflow→float (twentieth × twentieth)
     * - {@code $n ** 41} / {@code pow($n, 41)} → {@code ((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*$n}
     *   with chained smul overflow→float (fortieth × n)
     * - {@code $n ** 42} / {@code pow($n, 42)} → {@code ((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n)}
     *   with chained smul overflow→float (fortieth × square)
     * - {@code $n ** 43} / {@code pow($n, 43)} → {@code (((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*$n}
     *   with chained smul overflow→float (fortysecond × n)
     * - {@code $n ** 44} / {@code pow($n, 44)} → {@code (((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n)}
     *   with chained smul overflow→float (fortysecond × square)
     * - {@code $n ** 45} / {@code pow($n, 45)} → {@code ((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*$n}
     *   with chained smul overflow→float (fortyfourth × n)
     * - {@code $n ** 46} / {@code pow($n, 46)} → {@code ((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n)}
     *   with chained smul overflow→float (fortyfourth × square)
     * - {@code $n ** 47} / {@code pow($n, 47)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*$n}
     *   with chained smul overflow→float (fortysixth × n)
     * - {@code $n ** 48} / {@code pow($n, 48)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n)}
     *   with chained smul overflow→float (fortysixth × square)
     * - {@code $n ** 49} / {@code pow($n, 49)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n}
     *   with chained smul overflow→float (fortyeighth × n)
     * - {@code $n ** 50} / {@code pow($n, 50)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n)}
     *   with chained smul overflow→float (fortyeighth × square)
     * - {@code $n ** 51} / {@code pow($n, 51)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n}
     *   with chained smul overflow→float (fiftieth × n)
     * - {@code $n ** 52} / {@code pow($n, 52)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n)}
     *   with chained smul overflow→float (fiftyfirst × n)
     * - {@code $n ** 53} / {@code pow($n, 53)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n}
     *   with chained smul overflow→float (fiftysecond × n)
     * - {@code $n ** 54} / {@code pow($n, 54)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n}
     *   with chained smul overflow→float (fiftythird × n)
     * - {@code $n ** 55} / {@code pow($n, 55)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n}
     *   with chained smul overflow→float (fiftyfourth × n)
     * - {@code $n ** 56} / {@code pow($n, 56)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (fiftyfifth × n)
     * - {@code $n ** 57} / {@code pow($n, 57)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (fiftysixth × n)
     * - {@code $n ** 58} / {@code pow($n, 58)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (fiftyseventh × n)
     * - {@code $n ** 59} / {@code pow($n, 59)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (fiftyeighth × n)
     * - {@code $n ** 60} / {@code pow($n, 60)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (fiftyninth × n)
     * - {@code $n ** 61} / {@code pow($n, 61)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (sixtieth × n)
     * - {@code $n ** 62} / {@code pow($n, 62)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (sixtyfirst × n)
     * - {@code $n ** 63} / {@code pow($n, 63)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (sixtysecond × n)
     * - {@code $n ** 64} / {@code pow($n, 64)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (sixtythird × n)
     * - {@code $n ** 65} / {@code pow($n, 65)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n}
     *   with chained smul overflow→float (sixtyfourth × n)
     * - {@code $n ** 66} / {@code pow($n, 66)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n*$n}
     *   with chained smul overflow→float (sixtyfifth × n)
     * - {@code $n ** 67} / {@code pow($n, 67)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n*$n)*$n}
     *   with chained smul overflow→float (sixtysixth × n)
     * - {@code $n ** 68} / {@code pow($n, 68)} → {@code (((((((((((((($n*$n)*$n)*(($n*$n)))*((($n*$n)*$n)*(($n*$n))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*((($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n))*(($n*$n)*$n)*(($n*$n)))))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*($n*$n))*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n)*$n*$n)*$n)*$n}
     *   with chained smul overflow→float (sixtyseventh × n)
     * - {@code $n ** 69} / {@code pow($n, 69)} → chained smul overflow→float (sixtyeighth × n)
     * - {@code $n ** 70} / {@code pow($n, 70)} → chained smul overflow→float (sixtyninth × n)
     * - {@code $n ** 71} / {@code pow($n, 71)} → chained smul overflow→float (seventieth × n)
     * - {@code $n ** 72} / {@code pow($n, 72)} → chained smul overflow→float (seventyfirst × n)
     * - {@code $n ** 73} / {@code pow($n, 73)} → chained smul overflow→float (seventysecond × n)
     * - {@code $n ** 74} / {@code pow($n, 74)} → chained smul overflow→float (seventythird × n)
     * - {@code $n ** 75} / {@code pow($n, 75)} → chained smul overflow→float (seventyfourth × n)
     * - {@code $n ** 76} / {@code pow($n, 76)} → chained smul overflow→float (seventyfifth × n)
     * - {@code $n ** 77} / {@code pow($n, 77)} → chained smul overflow→float (seventysixth × n)
     * - {@code $n ** 78} / {@code pow($n, 78)} → chained smul overflow→float (seventyseventh × n)
     * - {@code $n ** 79} / {@code pow($n, 79)} → chained smul overflow→float (seventyeighth × n)
     * - {@code $n ** 80} / {@code pow($n, 80)} → chained smul overflow→float (seventyninth × n)
     * - {@code $n ** 81} / {@code pow($n, 81)} → chained smul overflow→float (eightieth × n)
     * - {@code $n ** 82} / {@code pow($n, 82)} → chained smul overflow→float (eightyfirst × n)
     * - {@code $n ** 83} / {@code pow($n, 83)} → chained smul overflow→float (eightysecond × n)
     * - {@code $n ** 84} / {@code pow($n, 84)} → chained smul overflow→float (eightythird × n)
     * - {@code $n ** 85} / {@code pow($n, 85)} → chained smul overflow→float (eightyfourth × n)
     *
     * Omits {@code llvm.pow.f64} and the siToFp/fpToSi round-trip on the
     * integer fast path ({@see \PHPCompiler\ext\standard\JitPow}). Peer
     * compile-time {@code * 1} identity ({@see nativeLongArithIsCompileTimeIdentityOrZero})
     * and {@code * 2^k} shl ({@see nativeLongMulCompileTimePowerOfTwoShift}).
     *
     * Float exponents ({@code 0.0}/{@code 1.0}/{@code 2.0}/{@code 3.0}/{@code 4.0}/{@code 5.0}/{@code 6.0}/{@code 7.0}/{@code 8.0}/{@code 9.0}/{@code 10.0}/{@code 11.0}/{@code 12.0}/{@code 13.0}/{@code 14.0}/{@code 15.0}/{@code 16.0}/{@code 17.0}/{@code 18.0}/{@code 19.0}/{@code 20.0}/{@code 21.0}/{@code 22.0}/{@code 23.0}/{@code 24.0}/{@code 25.0}/{@code 26.0}/{@code 27.0}/{@code 28.0}/{@code 29.0}/{@code 30.0}/{@code 31.0}/{@code 32.0}/{@code 33.0}/{@code 34.0}/{@code 35.0}/{@code 36.0}/{@code 37.0}/{@code 38.0}/{@code 39.0}/{@code 40.0}/{@code 41.0}/{@code 42.0}/{@code 43.0}/{@code 44.0}/{@code 45.0}/{@code 46.0}/{@code 47.0}/{@code 48.0}/{@code 49.0}/{@code 50.0}/{@code 51.0}/{@code 52.0}/{@code 53.0}/{@code 54.0}/{@code 55.0}/{@code 56.0}/{@code 57.0}/{@code 58.0}/{@code 59.0}/{@code 60.0}/{@code 61.0}/{@code 62.0}/{@code 63.0}/{@code 64.0}/{@code 65.0}/{@code 66.0}/{@code 67.0}/{@code 68.0}/{@code 69.0}/{@code 70.0}/{@code 71.0}/{@code 72.0}/{@code 73.0}/{@code 74.0}/{@code 75.0}/{@code 76.0}/{@code 77.0}/{@code 78.0}/{@code 79.0}/{@code 80.0}/{@code 81.0}/{@code 82.0}/{@code 83.0}/{@code 84.0}/{@code 85.0}) stay
     * on the float path — Zend returns {@code float} for those shapes.
     *
     * php-src: Zend/zend_operators.c {@code pow_function} /
     * {@code zend_pow} / {@code mul_function}; ext/standard/math.c
     * {@code PHP_FUNCTION(pow)}.
     *
     * @return 'one'|'identity'|'square'|'cube'|'fourth'|'fifth'|'sixth'|'seventh'|'eighth'|'ninth'|'tenth'|'eleventh'|'twelfth'|'thirteenth'|'fourteenth'|'fifteenth'|'sixteenth'|'seventeenth'|'eighteenth'|'nineteenth'|'twentieth'|'twentyfirst'|'twentysecond'|'twentythird'|'twentyfourth'|'twentyfifth'|'twentysixth'|'twentyseventh'|'twentyeighth'|'twentyninth'|'thirtieth'|'thirtyfirst'|'thirtysecond'|'thirtythird'|'thirtyfourth'|'thirtyfifth'|'thirtysixth'|'thirtyseventh'|'thirtyeighth'|'thirtyninth'|'fortieth'|'fortyfirst'|'fortysecond'|'fortythird'|'fortyfourth'|'fortyfifth'|'fortysixth'|'fortyseventh'|'fortyeighth'|'fortyninth'|'fiftieth'|'fiftyfirst'|'fiftysecond'|'fiftythird'|'fiftyfourth'|'fiftyfifth'|'fiftysixth'|'fiftyseventh'|'fiftyeighth'|'fiftyninth'|'sixtieth'|'sixtyfirst'|'sixtysecond'|'sixtythird'|'sixtyfourth'|'sixtyfifth'|'sixtysixth'|'sixtyseventh'|'sixtyeighth'|'sixtyninth'|'seventieth'|'seventyfirst'|'seventysecond'|'seventythird'|'seventyfourth'|'seventyfifth'|'seventysixth'|'seventyseventh'|'seventyeighth'|'seventyninth'|'eightieth'|'eightyfirst'|'eightysecond'|'eightythird'|'eightyfourth'|'eightyfifth'|null fold to 1, keep base, mul square/cube/fourth/fifth/sixth/seventh/eighth/ninth/tenth/eleventh/twelfth/thirteenth/fourteenth/fifteenth/sixteenth/seventeenth/eighteenth/nineteenth/twentieth/twentyfirst/twentysecond/twentythird/twentyfourth/twentyfifth/twentysixth/twentyseventh/twentyeighth/twentyninth/thirtieth/thirtyfirst/thirtysecond/thirtythird/thirtyfourth/thirtyfifth/thirtysixth/thirtyseventh/thirtyeighth/thirtyninth/fortieth/fortyfirst/fortysecond/fortythird/fortyfourth/fortyfifth/fortysixth/fortyseventh/fortyeighth/fortyninth/fiftieth/fiftyfirst/fiftysecond/fiftythird/fiftyfourth/fiftyfifth/fiftysixth/fiftyseventh/fiftyeighth/fiftyninth/sixtieth/sixtyfirst/sixtysecond/sixtythird/sixtyfourth, sixtyfifth, sixtysixth, sixtyseventh, sixtyeighth, sixtyninth, seventieth, seventyfirst, seventysecond, seventythird, seventyfourth, seventyfifth, seventysixth, seventyseventh, seventyeighth, seventyninth, eightieth, eightyfirst, eightysecond, eightythird, eightyfourth, eightyfifth, or null
     */
    public static function nativeLongPowCompileTimeExponentFold(
        Variable $exponent
    ): ?string {
        $e = self::compileTimeLongScalar($exponent);
        if (null === $e) {
            return null;
        }
        if (0 === $e) {
            return 'one';
        }
        if (1 === $e) {
            return 'identity';
        }
        if (2 === $e) {
            return 'square';
        }
        if (3 === $e) {
            return 'cube';
        }
        if (4 === $e) {
            return 'fourth';
        }
        if (5 === $e) {
            return 'fifth';
        }
        if (6 === $e) {
            return 'sixth';
        }
        if (7 === $e) {
            return 'seventh';
        }
        if (8 === $e) {
            return 'eighth';
        }
        if (9 === $e) {
            return 'ninth';
        }
        if (10 === $e) {
            return 'tenth';
        }
        if (11 === $e) {
            return 'eleventh';
        }
        if (12 === $e) {
            return 'twelfth';
        }
        if (13 === $e) {
            return 'thirteenth';
        }
        if (14 === $e) {
            return 'fourteenth';
        }
        if (15 === $e) {
            return 'fifteenth';
        }
        if (16 === $e) {
            return 'sixteenth';
        }
        if (17 === $e) {
            return 'seventeenth';
        }
        if (18 === $e) {
            return 'eighteenth';
        }
        if (19 === $e) {
            return 'nineteenth';
        }
        if (20 === $e) {
            return 'twentieth';
        }
        if (21 === $e) {
            return 'twentyfirst';
        }
        if (22 === $e) {
            return 'twentysecond';
        }
        if (23 === $e) {
            return 'twentythird';
        }
        if (24 === $e) {
            return 'twentyfourth';
        }
        if (25 === $e) {
            return 'twentyfifth';
        }
        if (26 === $e) {
            return 'twentysixth';
        }
        if (27 === $e) {
            return 'twentyseventh';
        }
        if (28 === $e) {
            return 'twentyeighth';
        }
        if (29 === $e) {
            return 'twentyninth';
        }
        if (30 === $e) {
            return 'thirtieth';
        }
        if (31 === $e) {
            return 'thirtyfirst';
        }
        if (32 === $e) {
            return 'thirtysecond';
        }
        if (33 === $e) {
            return 'thirtythird';
        }
        if (34 === $e) {
            return 'thirtyfourth';
        }
        if (35 === $e) {
            return 'thirtyfifth';
        }
        if (36 === $e) {
            return 'thirtysixth';
        }
        if (37 === $e) {
            return 'thirtyseventh';
        }
        if (38 === $e) {
            return 'thirtyeighth';
        }
        if (39 === $e) {
            return 'thirtyninth';
        }
        if (40 === $e) {
            return 'fortieth';
        }
        if (41 === $e) {
            return 'fortyfirst';
        }
        if (42 === $e) {
            return 'fortysecond';
        }
        if (43 === $e) {
            return 'fortythird';
        }
        if (44 === $e) {
            return 'fortyfourth';
        }
        if (45 === $e) {
            return 'fortyfifth';
        }
        if (46 === $e) {
            return 'fortysixth';
        }
        if (47 === $e) {
            return 'fortyseventh';
        }
        if (48 === $e) {
            return 'fortyeighth';
        }
        if (49 === $e) {
            return 'fortyninth';
        }
        if (50 === $e) {
            return 'fiftieth';
        }
        if (51 === $e) {
            return 'fiftyfirst';
        }
        if (52 === $e) {
            return 'fiftysecond';
        }
        if (53 === $e) {
            return 'fiftythird';
        }
        if (54 === $e) {
            return 'fiftyfourth';
        }
        if (55 === $e) {
            return 'fiftyfifth';
        }
        if (56 === $e) {
            return 'fiftysixth';
        }
        if (57 === $e) {
            return 'fiftyseventh';
        }
        if (58 === $e) {
            return 'fiftyeighth';
        }
        if (59 === $e) {
            return 'fiftyninth';
        }
        if (60 === $e) {
            return 'sixtieth';
        }
        if (61 === $e) {
            return 'sixtyfirst';
        }
        if (62 === $e) {
            return 'sixtysecond';
        }
        if (63 === $e) {
            return 'sixtythird';
        }
        if (64 === $e) {
            return 'sixtyfourth';
        }
        if (65 === $e) {
            return 'sixtyfifth';
        }
        if (66 === $e) {
            return 'sixtysixth';
        }
        if (67 === $e) {
            return 'sixtyseventh';
        }
        if (68 === $e) {
            return 'sixtyeighth';
        }
        if (69 === $e) {
            return 'sixtyninth';
        }
        if (70 === $e) {
            return 'seventieth';
        }
        if (71 === $e) {
            return 'seventyfirst';
        }
        if (72 === $e) {
            return 'seventysecond';
        }
        if (73 === $e) {
            return 'seventythird';
        }
        if (74 === $e) {
            return 'seventyfourth';
        }
        if (75 === $e) {
            return 'seventyfifth';
        }
        if (76 === $e) {
            return 'seventysixth';
        }
        if (77 === $e) {
            return 'seventyseventh';
        }
        if (78 === $e) {
            return 'seventyeighth';
        }
        if (79 === $e) {
            return 'seventyninth';
        }
        if (80 === $e) {
            return 'eightieth';
        }
        if (81 === $e) {
            return 'eightyfirst';
        }
        if (82 === $e) {
            return 'eightysecond';
        }
        if (83 === $e) {
            return 'eightythird';
        }
        if (84 === $e) {
            return 'eightyfourth';
        }
        if (85 === $e) {
            return 'eightyfifth';
        }

        return null;
    }
}
