/*
 * pow(int, int) integer result when possible (issue #3678).
 * php-src: Zend/zend_operators.c — pow_function
 */

#include <math.h>
#include <stddef.h>
#include <stdint.h>

struct __value__;

extern void __value__writeLong(struct __value__ *out, long long v);
extern void __value__writeDouble(struct __value__ *out, double v);

#define PHPC_LLONG_MAX 9223372036854775807LL
#define PHPC_LLONG_MIN (-9223372036854775807LL - 1LL)

static int phpc_mul_overflows(long long a, long long b)
{
    if (a > 0) {
        if (b > 0) {
            return a > PHPC_LLONG_MAX / b;
        }
        if (b < 0) {
            return b < PHPC_LLONG_MIN / a;
        }

        return 0;
    }
    if (a < 0) {
        if (b > 0) {
            return a < PHPC_LLONG_MIN / b;
        }
        if (b < 0) {
            return a != 0 && b < PHPC_LLONG_MAX / a;
        }

        return 0;
    }

    return 0;
}

static int phpc_ipow(long long base, long long exp, long long *out)
{
    long long result;
    long long b;
    long long e;

    if (exp < 0) {
        return 0;
    }
    if (0 == exp) {
        *out = 1;

        return 1;
    }

    result = 1;
    b = base;
    e = exp;
    while (e > 0) {
        if (e & 1) {
            if (phpc_mul_overflows(result, b)) {
                return 0;
            }
            result *= b;
        }
        e >>= 1;
        if (e > 0) {
            if (phpc_mul_overflows(b, b)) {
                return 0;
            }
            b *= b;
        }
    }

    *out = result;

    return 1;
}

void __phpc_pow_int(struct __value__ *out, long long base, long long exp)
{
    long long result;

    if (NULL == out) {
        return;
    }
    if (phpc_ipow(base, exp, &result)) {
        __value__writeLong(out, result);

        return;
    }
    __value__writeDouble(out, pow((double) base, (double) exp));
}
