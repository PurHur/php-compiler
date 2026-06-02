/*
 * round() precision and mode (php-src ext/standard/math.c — _php_math_round).
 */

#include <math.h>
#include <stdio.h>
#include <stdlib.h>

#define PHPC_ROUND_HALF_UP 1
#define PHPC_ROUND_HALF_DOWN 2
#define PHPC_ROUND_HALF_EVEN 3
#define PHPC_ROUND_HALF_ODD 4

static double phpc_intpow10(int power)
{
    static const double powers[] = {
        1e0, 1e1, 1e2, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11,
        1e12, 1e13, 1e14, 1e15, 1e16, 1e17, 1e18, 1e19, 1e20, 1e21, 1e22
    };

    if (power < 0 || power > 22) {
        return pow(10.0, (double) power);
    }

    return powers[power];
}

static double phpc_copysign_mag(double magnitude, double sign_source)
{
    return sign_source >= 0.0 ? magnitude : -magnitude;
}

static double phpc_round_basic_edge_case(double integral, double exponent, int places)
{
    if (places > 0) {
        return fabs((integral + phpc_copysign_mag(0.5, integral)) / exponent);
    }

    return fabs((integral + phpc_copysign_mag(0.5, integral)) * exponent);
}

static double phpc_round_helper(double integral, double value, double exponent, int places, int mode)
{
    double value_abs = fabs(value);
    double edge_case = phpc_round_basic_edge_case(integral, exponent, places);

    switch (mode) {
        case PHPC_ROUND_HALF_UP:
            if (value_abs >= edge_case) {
                return integral + phpc_copysign_mag(1.0, integral);
            }

            return integral;

        case PHPC_ROUND_HALF_DOWN:
            if (value_abs > edge_case) {
                return integral + phpc_copysign_mag(1.0, integral);
            }

            return integral;

        case PHPC_ROUND_HALF_EVEN:
            if (value_abs > edge_case) {
                return integral + phpc_copysign_mag(1.0, integral);
            }
            if (value_abs == edge_case) {
                int even = fmod(integral, 2.0) == 0.0;
                if (!even) {
                    return integral + phpc_copysign_mag(1.0, integral);
                }
            }

            return integral;

        case PHPC_ROUND_HALF_ODD:
            if (value_abs > edge_case) {
                return integral + phpc_copysign_mag(1.0, integral);
            }
            if (value_abs == edge_case) {
                int even = fmod(integral, 2.0) == 0.0;
                if (even) {
                    return integral + phpc_copysign_mag(1.0, integral);
                }
            }

            return integral;

        default:
            return integral;
    }
}

static double phpc_math_round(double value, int places, int mode)
{
    double exponent;
    double tmp_value;
    double tmp_value2;

    if (!isfinite(value) || value == 0.0) {
        return value;
    }

    exponent = phpc_intpow10(abs(places));

    if (value >= 0.0) {
        tmp_value = floor(places > 0 ? value * exponent : value / exponent);
        tmp_value2 = tmp_value + 1.0;
    } else {
        tmp_value = ceil(places > 0 ? value * exponent : value / exponent);
        tmp_value2 = tmp_value - 1.0;
    }

    if ((places > 0 ? tmp_value2 / exponent : tmp_value2 * exponent) == value) {
        tmp_value = tmp_value2;
    }

    if (fabs(tmp_value) >= 1e16) {
        return value;
    }

    tmp_value = phpc_round_helper(tmp_value, value, exponent, places, mode);

    if (abs(places) < 23) {
        if (places > 0) {
            return tmp_value / exponent;
        }

        return tmp_value * exponent;
    }

    {
        char buf[40];
        double converted;

        snprintf(buf, sizeof(buf), "%15fe%d", tmp_value, -places);
        converted = strtod(buf, NULL);
        if (!isfinite(converted) || isnan(converted)) {
            return value;
        }

        return converted;
    }
}

double __compiler_round(double value, long long precision, long long mode)
{
    int places;

    if (precision >= 0) {
        places = precision > 2147483647LL ? 2147483647 : (int) precision;
    } else {
        places = precision < -2147483648LL ? -2147483648 : (int) precision;
    }

    return phpc_math_round(value, places, (int) mode);
}
