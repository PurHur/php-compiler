/*
 * getdate() runtime for JIT/AOT (issue #3510).
 * Uses localtime_r(3); php-src reference: ext/standard/datetime.c
 */

#include <stddef.h>
#include <string.h>
#include <time.h>

typedef struct __string__ __string__;
typedef struct __value__ __value__;
typedef struct __hashtable__ __hashtable__;

extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);
extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setLongAt(__hashtable__ *ht, size_t index, long long val);

static __string__ *gd_key(const char *key)
{
    return __string__init((long long) strlen(key), key);
}

static __string__ *gd_cstr(const char *value)
{
    return __string__init((long long) strlen(value), value);
}

static const char *gd_weekday(int wday)
{
    static const char *names[] = {
        "Sunday",
        "Monday",
        "Tuesday",
        "Wednesday",
        "Thursday",
        "Friday",
        "Saturday",
    };

    if (wday < 0 || wday > 6) {
        return "Sunday";
    }

    return names[wday];
}

static const char *gd_month(int mon)
{
    static const char *names[] = {
        "January",
        "February",
        "March",
        "April",
        "May",
        "June",
        "July",
        "August",
        "September",
        "October",
        "November",
        "December",
    };

    if (mon < 0 || mon > 11) {
        return "January";
    }

    return names[mon];
}

void __compiler_getdate(long long timestamp, __value__ *out)
{
    time_t ts;
    struct tm tm_buf;
    struct tm *tm;
    __hashtable__ *ht;

    if (NULL == out) {
        return;
    }

    ts = (time_t) timestamp;
    tm = localtime_r(&ts, &tm_buf);
    if (NULL == tm) {
        return;
    }

    ht = __hashtable__alloc();
    if (NULL == ht) {
        return;
    }

    __hashtable__setStringKeyLong(ht, gd_key("seconds"), (long long) tm->tm_sec);
    __hashtable__setStringKeyLong(ht, gd_key("minutes"), (long long) tm->tm_min);
    __hashtable__setStringKeyLong(ht, gd_key("hours"), (long long) tm->tm_hour);
    __hashtable__setStringKeyLong(ht, gd_key("mday"), (long long) tm->tm_mday);
    __hashtable__setStringKeyLong(ht, gd_key("wday"), (long long) tm->tm_wday);
    __hashtable__setStringKeyLong(ht, gd_key("mon"), (long long) (tm->tm_mon + 1));
    __hashtable__setStringKeyLong(ht, gd_key("year"), (long long) (tm->tm_year + 1900));
    __hashtable__setStringKeyLong(ht, gd_key("yday"), (long long) tm->tm_yday);
    __hashtable__setStringKeyString(ht, gd_key("weekday"), gd_cstr(gd_weekday(tm->tm_wday)));
    __hashtable__setStringKeyString(ht, gd_key("month"), gd_cstr(gd_month(tm->tm_mon)));
    __hashtable__setLongAt(ht, 0, (long long) ts);

    __value__writeHashtable(out, ht);
}
