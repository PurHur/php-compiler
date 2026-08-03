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

    public static function inetPton(string $address): ?string
    {
        $result = VmInet::inet_pton($address);
        if (false === $result) {
            return null;
        }

        return $result;
    }

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
