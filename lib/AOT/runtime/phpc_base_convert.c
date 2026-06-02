/*
 * base_convert() runtime for VM/JIT/AOT (issue #3173).
 * php-src: ext/standard/math.c — _php_math_basetozval, _php_math_zvaltobase, PHP_FUNCTION(base_convert)
 */

#include <ctype.h>
#include <math.h>
#include <stddef.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __value__ __value__;

extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeLong(__value__ *out, long long value);
extern void __value__writeDouble(__value__ *out, double value);

int phpc_basetozval_result(const char *str, long long base, long long *out_long, double *out_double);

static const char digits[] = "0123456789abcdefghijklmnopqrstuvwxyz";

static int phpc_digit_value(char c)
{
    if (c >= '0' && c <= '9') {
        return c - '0';
    }
    if (c >= 'A' && c <= 'Z') {
        return c - 'A' + 10;
    }
    if (c >= 'a' && c <= 'z') {
        return c - 'a' + 10;
    }

    return -1;
}

static int phpc_basetozval(const char *str, int base, int64_t *out_long, double *out_double, int *is_double)
{
    size_t len;
    size_t start = 0;
    size_t end;
    int64_t num = 0;
    double fnum = 0.0;
    int mode = 0;
    int64_t cutoff;
    int cutlim;

    if (NULL == str) {
        str = "";
    }
    len = strlen(str);
    end = len;

    while (start < end && isspace((unsigned char) str[start])) {
        start++;
    }
    while (end > start && isspace((unsigned char) str[end - 1])) {
        end--;
    }

    if (end - start >= 2) {
        if (base == 16 && str[start] == '0' && (str[start + 1] == 'x' || str[start + 1] == 'X')) {
            start += 2;
        } else if (base == 8 && str[start] == '0' && (str[start + 1] == 'o' || str[start + 1] == 'O')) {
            start += 2;
        } else if (base == 2 && str[start] == '0' && (str[start + 1] == 'b' || str[start + 1] == 'B')) {
            start += 2;
        }
    }

    cutoff = INT64_MAX / base;
    cutlim = (int) (INT64_MAX % base);

    for (size_t i = start; i < end; i++) {
        int digit = phpc_digit_value(str[i]);
        if (digit < 0 || digit >= base) {
            continue;
        }

        if (mode == 0) {
            if (num < cutoff || (num == cutoff && digit <= cutlim)) {
                num = num * base + digit;
                continue;
            }
            fnum = (double) num;
            mode = 1;
        }
        fnum = fnum * base + digit;
    }

    if (mode == 1) {
        *out_double = fnum;
        *is_double = 1;

        return 0;
    }

    *out_long = num;
    *is_double = 0;

    return 0;
}

__string__ *phpc_longtobase_str(long long arg, int base)
{
    char buf[128];
    char *ptr;
    char *end;
    int negative = 0;
    uint64_t value;

    if (base < 2 || base > 36) {
        return __string__init(0, "");
    }

    if (arg == 0) {
        return __string__init(1, "0");
    }

    if (arg < 0) {
        negative = 1;
        value = (uint64_t) (-(arg + 1)) + 1U;
    } else {
        value = (uint64_t) arg;
    }

    end = ptr = buf + sizeof(buf) - 1;
    *ptr = '\0';

    do {
        *--ptr = digits[value % (unsigned) base];
        value /= (unsigned) base;
    } while (value > 0 && ptr > buf);

    if (negative) {
        *--ptr = '-';
    }

    return __string__init((long long) (end - ptr), ptr);
}

static __string__ *phpc_doubletobase(double fvalue, int base)
{
    char buf[128];
    char *ptr;
    char *end;
    int negative = 0;

    if (base < 2 || base > 36) {
        return __string__init(0, "");
    }

    if (fvalue == INFINITY || fvalue == -INFINITY) {
        return __string__init(0, "");
    }

    fvalue = floor(fvalue);
    if (fvalue == 0.0) {
        return __string__init(1, "0");
    }

    if (fvalue < 0.0) {
        negative = 1;
        fvalue = -fvalue;
    }

    end = ptr = buf + sizeof(buf) - 1;
    *ptr = '\0';

    while (ptr > buf && fvalue >= 1.0) {
        int digit = (int) fmod(fvalue, (double) base);
        *--ptr = digits[digit];
        fvalue /= (double) base;
    }

    if (negative) {
        *--ptr = '-';
    }

    return __string__init((long long) (end - ptr), ptr);
}

void phpc_basetozval_write(__value__ *out, const char *str, long long base)
{
    int64_t lval;
    double dval;
    int is_double = 0;

    if (NULL == out) {
        return;
    }

    phpc_basetozval(str, (int) base, &lval, &dval, &is_double);

    if (is_double) {
        __value__writeDouble(out, dval);
    } else {
        __value__writeLong(out, (long long) lval);
    }
}

int phpc_basetozval_result(const char *str, long long base, long long *out_long, double *out_double)
{
    int64_t lval = 0;
    double dval = 0.0;
    int is_double = 0;

    phpc_basetozval(str, (int) base, &lval, &dval, &is_double);
    if (NULL != out_long) {
        *out_long = (long long) lval;
    }
    if (NULL != out_double) {
        *out_double = dval;
    }

    return is_double;
}

__string__ *phpc_base_convert(const char *num, long long from_base, long long to_base)
{
    int64_t lval;
    double dval;
    int is_double = 0;
    int from = (int) from_base;
    int to = (int) to_base;

    if (from < 2 || from > 36 || to < 2 || to > 36) {
        return __string__init(0, "");
    }

    phpc_basetozval(num, from, &lval, &dval, &is_double);

    if (is_double) {
        return phpc_doubletobase(dval, to);
    }

    return phpc_longtobase_str((long long) lval, to);
}
