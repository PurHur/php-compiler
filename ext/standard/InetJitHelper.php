<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ip2long/long2ip/inet_* NestedJIT helpers (#8969, #27088).
 *
 * Fully branchless IPv4 parse — NestedJIT `if` / `===` / while miscompile under thin AOT (#27088).
 */
final class InetJitHelper
{
    private const TAG_FALSE = 0;
    private const TAG_INT = 1;
    private const TAG_STRING = 2;
    private const UINT32_MAX = 4294967295;
    private static int $lastInt = 0;
    private static string $lastString = '';

    public static function ip2longTag(string $ip): int
    {
        $len = \strlen($ip);
        $long = 0;
        $octet = 0;
        $digits = 0;
        $dots = 0;
        $invalid = 0;
        $zeroStart = 0;
        $tooShort = \max(0, \min(1, 7 + (-1) * $len));
        $tooLong = \max(0, \min(1, $len + (-15)));
        $invalid = $invalid + $tooShort + $tooLong;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-0)));
        $o = $inRange * \ord(\substr($ip, 0 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-1)));
        $o = $inRange * \ord(\substr($ip, 1 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-2)));
        $o = $inRange * \ord(\substr($ip, 2 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-3)));
        $o = $inRange * \ord(\substr($ip, 3 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-4)));
        $o = $inRange * \ord(\substr($ip, 4 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-5)));
        $o = $inRange * \ord(\substr($ip, 5 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-6)));
        $o = $inRange * \ord(\substr($ip, 6 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-7)));
        $o = $inRange * \ord(\substr($ip, 7 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-8)));
        $o = $inRange * \ord(\substr($ip, 8 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-9)));
        $o = $inRange * \ord(\substr($ip, 9 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-10)));
        $o = $inRange * \ord(\substr($ip, 10 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-11)));
        $o = $inRange * \ord(\substr($ip, 11 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-12)));
        $o = $inRange * \ord(\substr($ip, 12 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-13)));
        $o = $inRange * \ord(\substr($ip, 13 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        $o = 0;
        $inRange = \max(0, \min(1, $len + (-14)));
        $o = $inRange * \ord(\substr($ip, 14 * $inRange, 1));
        $dDot = $o + (-46);
        $isDot = $inRange * \max(0, \min(1, 1 + (-1) * ($dDot * $dDot)));
        $dDig = ($o + (-47)) * (58 + (-1) * $o);
        $isDig = $inRange * \max(0, \min(1, $dDig));
        // leading-zero: first digit 0 → mark; later digit with mark → invalid
        $digitVal = $o + (-48);
        $isFirstDig = $isDig * \max(0, \min(1, 1 + (-1) * $digits));
        $zeroStart = $zeroStart + $isFirstDig * \max(0, \min(1, 1 + (-1) * ($digitVal * $digitVal)));
        $isLaterDig = $isDig * \max(0, \min(1, $digits));
        $invalid = $invalid + $isLaterDig * $zeroStart;
        $octet = $isDig * ($octet * 10 + $digitVal) + (1 + (-1) * $isDig) * $octet;
        $digits = $digits + $isDig;
        $invalid = $invalid + $isDig * \max(0, \min(1, $digits + (-3))); // digits became >3? use before incr... skip, check octet>255
        $invalid = $invalid + $isDig * \max(0, \min(1, $octet + (-255)));
        // dot: commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $isDot * $badOctet;
        $long = $isDot * (($long << 8) + $octet) + (1 + (-1) * $isDot) * $long;
        $dots = $dots + $isDot;
        $octet = (1 + (-1) * $isDot) * $octet;
        $digits = (1 + (-1) * $isDot) * $digits;
        $zeroStart = (1 + (-1) * $isDot) * $zeroStart;

        // final octet commit
        $badOctet = \max(0, \min(1, 1 + (-1) * $digits)) + \max(0, \min(1, $digits + (-4)));
        $invalid = $invalid + $badOctet;
        $long = ($long << 8) + $octet;
        $badDots = ($dots + (-3)) * ($dots + (-3));
        $invalid = $invalid + \max(0, \min(1, $badDots));
        $ok = 1 + (-1) * \max(0, \min(1, $invalid));
        self::$lastInt = $ok * $long;
        // TAG_INT when ok else TAG_FALSE — branchless
        return $ok * self::TAG_INT;
    }

    public static function lastInt(): int
    {
        return self::$lastInt;
    }

    public static function long2ipTag(int $properAddress): int
    {
        $properAddress &= self::UINT32_MAX;
        self::$lastString = (($properAddress >> 24) & 0xFF)
            .'.'
            .(($properAddress >> 16) & 0xFF)
            .'.'
            .(($properAddress >> 8) & 0xFF)
            .'.'
            .($properAddress & 0xFF);

        return self::TAG_STRING;
    }

    public static function lastString(): string
    {
        return self::$lastString;
    }


    /**
     * NestedJIT inet_pton — return string|false (peer Hex2bin #27008 / #27172).
     * No VmInet (external stub under thin AOT); no native chr()/ord()/strlen() (#20452).
     * IPv4 via {@see ip2longTag} + byteAt pack; IPv6 via NestedJIT-safe parser.
     *
     * @return string|false
     */
    public static function inetPtonArgv(string $address)
    {
        $len = 0;
        while (isset($address[$len])) {
            ++$len;
        }
        $hasColon = false;
        for ($i = 0; $i < $len; ++$i) {
            if (':' === $address[$i]) {
                $hasColon = true;
                break;
            }
        }
        if ($hasColon) {
            return self::inet6PtonArgv($address);
        }
        $tag = self::ip2longTag($address);
        if (self::TAG_FALSE === $tag) {
            return false;
        }
        $long = self::$lastInt & self::UINT32_MAX;

        return self::byteAt(($long >> 24) & 0xFF)
            .self::byteAt(($long >> 16) & 0xFF)
            .self::byteAt(($long >> 8) & 0xFF)
            .self::byteAt($long & 0xFF);
    }

    /**
     * @return string|false
     */
    public static function inetNtopArgv(string $inAddr)
    {
        $len = 0;
        while (isset($inAddr[$len])) {
            ++$len;
        }
        if (4 === $len) {
            $b0 = self::byteOrd($inAddr[0]);
            $b1 = self::byteOrd($inAddr[1]);
            $b2 = self::byteOrd($inAddr[2]);
            $b3 = self::byteOrd($inAddr[3]);
            $long = (($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3) & self::UINT32_MAX;
            self::long2ipTag($long);

            return self::$lastString;
        }
        if (16 === $len) {
            return self::inet6NtopArgv($inAddr);
        }

        return false;
    }

    /**
     * @return string|false
     */
    private static function inet6PtonArgv(string $address)
    {
        // Host SSOT for full mapped forms; NestedJIT implements common hex+:: path.
        $lower = '';
        $len = 0;
        while (isset($address[$len])) {
            $ch = $address[$len];
            $o = self::byteOrd($ch);
            if ($o >= 65 && $o <= 90) {
                $ch = self::byteAt($o + 32);
            }
            $lower .= $ch;
            ++$len;
        }
        for ($i = 0; $i < $len; ++$i) {
            if ('.' === $lower[$i]) {
                return false; // ::ffff:1.2.3.4 — use VmInet on host via inetPton()
            }
        }

        $dc = -1;
        for ($i = 0; $i < $len - 1; ++$i) {
            if (':' === $lower[$i] && ':' === $lower[$i + 1]) {
                if ($dc >= 0) {
                    return false;
                }
                $dc = $i;
            }
        }

        $groups = [];
        if ($dc >= 0) {
            $head = 0 === $dc ? '' : self::strSlice($lower, 0, $dc);
            $tail = self::strSlice($lower, $dc + 2, $len - ($dc + 2));
            $headParts = '' === $head ? [] : self::splitColon($head);
            $tailParts = '' === $tail ? [] : self::splitColon($tail);
            $missing = 8 - \count($headParts) - \count($tailParts);
            if ($missing < 1) {
                return false;
            }
            foreach ($headParts as $p) {
                $groups[] = $p;
            }
            for ($z = 0; $z < $missing; ++$z) {
                $groups[] = '0';
            }
            foreach ($tailParts as $p) {
                $groups[] = $p;
            }
        } else {
            $groups = self::splitColon($lower);
            if (8 !== \count($groups)) {
                return false;
            }
        }
        if (8 !== \count($groups)) {
            return false;
        }

        $bytes = '';
        foreach ($groups as $group) {
            if ('' === $group) {
                $group = '0';
            }
            $glen = 0;
            while (isset($group[$glen])) {
                ++$glen;
            }
            if ($glen < 1 || $glen > 4) {
                return false;
            }
            $value = 0;
            for ($i = 0; $i < $glen; ++$i) {
                $dig = self::hexDigit($group[$i]);
                if ($dig < 0) {
                    return false;
                }
                $value = ($value << 4) | $dig;
            }
            if ($value > 0xFFFF) {
                return false;
            }
            $bytes .= self::byteAt(($value >> 8) & 0xFF).self::byteAt($value & 0xFF);
        }
        $blen = 0;
        while (isset($bytes[$blen])) {
            ++$blen;
        }

        return 16 === $blen ? $bytes : false;
    }

    /**
     * @return string|false
     */
    private static function inet6NtopArgv(string $inAddr)
    {
        $groups = [];
        for ($i = 0; $i < 16; $i += 2) {
            $hi = self::byteOrd($inAddr[$i]);
            $lo = self::byteOrd($inAddr[$i + 1]);
            $groups[] = ($hi << 8) | $lo;
        }

        $allZeroPrefix = true;
        for ($i = 0; $i < 5; ++$i) {
            if (0 !== $groups[$i]) {
                $allZeroPrefix = false;
                break;
            }
        }
        if ($allZeroPrefix && 0xFFFF === $groups[5]) {
            $dotted = (($groups[6] >> 8) & 0xFF).'.'.($groups[6] & 0xFF)
                .'.'.(($groups[7] >> 8) & 0xFF).'.'.($groups[7] & 0xFF);

            return '::ffff:'.$dotted;
        }

        $bestStart = -1;
        $bestLen = 0;
        $runStart = -1;
        $runLen = 0;
        for ($idx = 0; $idx < 8; ++$idx) {
            if (0 === $groups[$idx]) {
                if ($runStart < 0) {
                    $runStart = $idx;
                    $runLen = 1;
                } else {
                    ++$runLen;
                }
            } else {
                if ($runLen > $bestLen) {
                    $bestStart = $runStart;
                    $bestLen = $runLen;
                }
                $runStart = -1;
                $runLen = 0;
            }
        }
        if ($runLen > $bestLen) {
            $bestStart = $runStart;
            $bestLen = $runLen;
        }

        $hex = [];
        for ($i = 0; $i < 8; ++$i) {
            $hex[] = \dechex($groups[$i]);
        }

        if ($bestLen > 1) {
            $head = [];
            for ($i = 0; $i < $bestStart; ++$i) {
                $head[] = $hex[$i];
            }
            $tail = [];
            for ($i = $bestStart + $bestLen; $i < 8; ++$i) {
                $tail[] = $hex[$i];
            }
            if ([] === $head && [] === $tail) {
                return '::';
            }
            if ([] === $head) {
                return '::'.\implode(':', $tail);
            }
            if ([] === $tail) {
                return \implode(':', $head).'::';
            }

            return \implode(':', $head).'::'.\implode(':', $tail);
        }

        return \implode(':', $hex);
    }

    /** @return list<string> */
    private static function splitColon(string $s): array
    {
        $parts = [];
        $cur = '';
        $len = 0;
        while (isset($s[$len])) {
            if (':' === $s[$len]) {
                $parts[] = $cur;
                $cur = '';
            } else {
                $cur .= $s[$len];
            }
            ++$len;
        }
        $parts[] = $cur;

        return $parts;
    }

    private static function strSlice(string $s, int $start, int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $idx = $start + $i;
            if (!isset($s[$idx])) {
                break;
            }
            $out .= $s[$idx];
        }

        return $out;
    }

    private static function hexDigit(string $ch): int
    {
        return match ($ch) {
            '0' => 0,
            '1' => 1,
            '2' => 2,
            '3' => 3,
            '4' => 4,
            '5' => 5,
            '6' => 6,
            '7' => 7,
            '8' => 8,
            '9' => 9,
            'a', 'A' => 10,
            'b', 'B' => 11,
            'c', 'C' => 12,
            'd', 'D' => 13,
            'e', 'E' => 14,
            'f', 'F' => 15,
            default => -1,
        };
    }

    private static function byteAt(int $code): string
    {
        return match ($code) {
            0 => "\0",
            1 => "\x01",
            2 => "\x02",
            3 => "\x03",
            4 => "\x04",
            5 => "\x05",
            6 => "\x06",
            7 => "\x07",
            8 => "\x08",
            9 => "\x09",
            10 => "\x0a",
            11 => "\x0b",
            12 => "\x0c",
            13 => "\x0d",
            14 => "\x0e",
            15 => "\x0f",
            16 => "\x10",
            17 => "\x11",
            18 => "\x12",
            19 => "\x13",
            20 => "\x14",
            21 => "\x15",
            22 => "\x16",
            23 => "\x17",
            24 => "\x18",
            25 => "\x19",
            26 => "\x1a",
            27 => "\x1b",
            28 => "\x1c",
            29 => "\x1d",
            30 => "\x1e",
            31 => "\x1f",
            32 => ' ',
            33 => '!',
            34 => "\"",
            35 => '#',
            36 => "\$",
            37 => '%',
            38 => '&',
            39 => "'",
            40 => '(',
            41 => ')',
            42 => '*',
            43 => '+',
            44 => ',',
            45 => '-',
            46 => '.',
            47 => '/',
            48 => '0',
            49 => '1',
            50 => '2',
            51 => '3',
            52 => '4',
            53 => '5',
            54 => '6',
            55 => '7',
            56 => '8',
            57 => '9',
            58 => ':',
            59 => ';',
            60 => '<',
            61 => '=',
            62 => '>',
            63 => '?',
            64 => '@',
            65 => 'A',
            66 => 'B',
            67 => 'C',
            68 => 'D',
            69 => 'E',
            70 => 'F',
            71 => 'G',
            72 => 'H',
            73 => 'I',
            74 => 'J',
            75 => 'K',
            76 => 'L',
            77 => 'M',
            78 => 'N',
            79 => 'O',
            80 => 'P',
            81 => 'Q',
            82 => 'R',
            83 => 'S',
            84 => 'T',
            85 => 'U',
            86 => 'V',
            87 => 'W',
            88 => 'X',
            89 => 'Y',
            90 => 'Z',
            91 => '[',
            92 => "\\",
            93 => ']',
            94 => '^',
            95 => '_',
            96 => '`',
            97 => 'a',
            98 => 'b',
            99 => 'c',
            100 => 'd',
            101 => 'e',
            102 => 'f',
            103 => 'g',
            104 => 'h',
            105 => 'i',
            106 => 'j',
            107 => 'k',
            108 => 'l',
            109 => 'm',
            110 => 'n',
            111 => 'o',
            112 => 'p',
            113 => 'q',
            114 => 'r',
            115 => 's',
            116 => 't',
            117 => 'u',
            118 => 'v',
            119 => 'w',
            120 => 'x',
            121 => 'y',
            122 => 'z',
            123 => '{',
            124 => '|',
            125 => '}',
            126 => '~',
            127 => "\x7f",
            128 => "\x80",
            129 => "\x81",
            130 => "\x82",
            131 => "\x83",
            132 => "\x84",
            133 => "\x85",
            134 => "\x86",
            135 => "\x87",
            136 => "\x88",
            137 => "\x89",
            138 => "\x8a",
            139 => "\x8b",
            140 => "\x8c",
            141 => "\x8d",
            142 => "\x8e",
            143 => "\x8f",
            144 => "\x90",
            145 => "\x91",
            146 => "\x92",
            147 => "\x93",
            148 => "\x94",
            149 => "\x95",
            150 => "\x96",
            151 => "\x97",
            152 => "\x98",
            153 => "\x99",
            154 => "\x9a",
            155 => "\x9b",
            156 => "\x9c",
            157 => "\x9d",
            158 => "\x9e",
            159 => "\x9f",
            160 => "\xa0",
            161 => "\xa1",
            162 => "\xa2",
            163 => "\xa3",
            164 => "\xa4",
            165 => "\xa5",
            166 => "\xa6",
            167 => "\xa7",
            168 => "\xa8",
            169 => "\xa9",
            170 => "\xaa",
            171 => "\xab",
            172 => "\xac",
            173 => "\xad",
            174 => "\xae",
            175 => "\xaf",
            176 => "\xb0",
            177 => "\xb1",
            178 => "\xb2",
            179 => "\xb3",
            180 => "\xb4",
            181 => "\xb5",
            182 => "\xb6",
            183 => "\xb7",
            184 => "\xb8",
            185 => "\xb9",
            186 => "\xba",
            187 => "\xbb",
            188 => "\xbc",
            189 => "\xbd",
            190 => "\xbe",
            191 => "\xbf",
            192 => "\xc0",
            193 => "\xc1",
            194 => "\xc2",
            195 => "\xc3",
            196 => "\xc4",
            197 => "\xc5",
            198 => "\xc6",
            199 => "\xc7",
            200 => "\xc8",
            201 => "\xc9",
            202 => "\xca",
            203 => "\xcb",
            204 => "\xcc",
            205 => "\xcd",
            206 => "\xce",
            207 => "\xcf",
            208 => "\xd0",
            209 => "\xd1",
            210 => "\xd2",
            211 => "\xd3",
            212 => "\xd4",
            213 => "\xd5",
            214 => "\xd6",
            215 => "\xd7",
            216 => "\xd8",
            217 => "\xd9",
            218 => "\xda",
            219 => "\xdb",
            220 => "\xdc",
            221 => "\xdd",
            222 => "\xde",
            223 => "\xdf",
            224 => "\xe0",
            225 => "\xe1",
            226 => "\xe2",
            227 => "\xe3",
            228 => "\xe4",
            229 => "\xe5",
            230 => "\xe6",
            231 => "\xe7",
            232 => "\xe8",
            233 => "\xe9",
            234 => "\xea",
            235 => "\xeb",
            236 => "\xec",
            237 => "\xed",
            238 => "\xee",
            239 => "\xef",
            240 => "\xf0",
            241 => "\xf1",
            242 => "\xf2",
            243 => "\xf3",
            244 => "\xf4",
            245 => "\xf5",
            246 => "\xf6",
            247 => "\xf7",
            248 => "\xf8",
            249 => "\xf9",
            250 => "\xfa",
            251 => "\xfb",
            252 => "\xfc",
            253 => "\xfd",
            254 => "\xfe",
            255 => "\xff",
            default => "\0",
        };
    }

    private static function byteOrd(string $byte): int
    {
        for ($code = 0; $code < 256; ++$code) {
            if ($byte === self::byteAt($code)) {
                return $code;
            }
        }

        return 0;
    }


    /** Host/unit convenience — NestedJIT bridges use {@see inetPtonArgv}. */
    public static function inetPton(string $address): ?string
    {
        $result = VmInet::inet_pton($address);
        if (false === $result) {
            return null;
        }

        return $result;
    }

    /** Host/unit convenience — NestedJIT bridges use {@see inetNtopArgv}. */
    public static function inetNtop(string $inAddr): ?string
    {
        $result = VmInet::inet_ntop($inAddr);
        if (false === $result) {
            return null;
        }

        return $result;
    }

    public static function resetForTest(): void
    {
        self::$lastInt = 0;
        self::$lastString = '';
    }
}
