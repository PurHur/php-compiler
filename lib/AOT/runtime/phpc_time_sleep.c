/*
 * time_nanosleep() / time_sleep_until() runtime for JIT/AOT (issue #5180).
 * POSIX nanosleep(3) + gettimeofday(3); php-src ext/standard/basic_functions.c.
 */

#include <errno.h>
#include <stdint.h>
#include <sys/time.h>
#include <time.h>

/** @return 1 true, 0 false */
int __compiler_time_nanosleep(long long sec, long long nsec)
{
    struct timespec req;
    struct timespec rem;

    req.tv_sec = (time_t) sec;
    req.tv_nsec = (long) nsec;

    while (0 != nanosleep(&req, &rem)) {
        if (EINTR == errno) {
            req = rem;
            continue;
        }

        return 0;
    }

    return 1;
}

/** @return 1 true, 0 false (past timestamp or failure; warning omitted in native path) */
int __compiler_time_sleep_until(double target_secs)
{
    struct timeval tm;
    struct timespec req;
    struct timespec rem;
    uint64_t current_ns;
    uint64_t target_ns;
    uint64_t diff_ns;
    const uint64_t ns_per_sec = 1000000000ULL;

    if (0 != gettimeofday(&tm, NULL)) {
        return 0;
    }

    target_ns = (uint64_t) (target_secs * (double) ns_per_sec);
    current_ns = ((uint64_t) tm.tv_sec) * ns_per_sec + ((uint64_t) tm.tv_usec) * 1000ULL;
    if (target_ns < current_ns) {
        return 0;
    }

    diff_ns = target_ns - current_ns;
    req.tv_sec = (time_t) (diff_ns / ns_per_sec);
    req.tv_nsec = (long) (diff_ns % ns_per_sec);

    while (0 != nanosleep(&req, &rem)) {
        if (EINTR == errno) {
            req = rem;
            continue;
        }

        return 0;
    }

    return 1;
}
