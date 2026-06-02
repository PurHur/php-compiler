/*
 * gethostbynamel() runtime for JIT/AOT (issue #3707).
 * php-src: ext/standard/dns.c PHP_FUNCTION(gethostbynamel)
 */

#include <arpa/inet.h>
#include <netdb.h>
#include <stddef.h>
#include <string.h>
#include <sys/socket.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;

extern __hashtable__ *__hashtable__alloc(void);
extern __string__ *__string__init(long long size, const char *value);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);

#define GHBL_MAX_ADDRS 64

static int ghbl_copy_hostname(__string__ *hostname, char *buf, size_t buflen)
{
    size_t len;

    if (NULL == hostname || buflen < 2) {
        return 0;
    }
    len = (size_t) *((long long *) ((char *) hostname + sizeof(void *)));
    if (0 == len || len >= buflen) {
        return 0;
    }
    memcpy(buf, (const char *) hostname + sizeof(void *) + sizeof(long long), len);
    buf[len] = '\0';

    return 1;
}

static int ghbl_has_ip(const char stored[][INET_ADDRSTRLEN], size_t count, const char *candidate)
{
    size_t i;

    for (i = 0; i < count; i++) {
        if (0 == strcmp(stored[i], candidate)) {
            return 1;
        }
    }

    return 0;
}

__hashtable__ *__compiler_gethostbynamel(__string__ *hostname)
{
    struct addrinfo hints;
    struct addrinfo *res = NULL;
    struct addrinfo *rp;
    char hostbuf[256];
    char ipbuf[INET_ADDRSTRLEN];
    char stored[GHBL_MAX_ADDRS][INET_ADDRSTRLEN];
    size_t count = 0;
    __hashtable__ *ht;

    if (!ghbl_copy_hostname(hostname, hostbuf, sizeof(hostbuf))) {
        return NULL;
    }

    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_INET;
    hints.ai_socktype = SOCK_STREAM;

    if (0 != getaddrinfo(hostbuf, NULL, &hints, &res)) {
        return NULL;
    }

    for (rp = res; NULL != rp; rp = rp->ai_next) {
        struct sockaddr_in *sin;

        if (AF_INET != rp->ai_family || NULL == rp->ai_addr) {
            continue;
        }
        sin = (struct sockaddr_in *) rp->ai_addr;
        if (NULL == inet_ntop(AF_INET, &sin->sin_addr, ipbuf, sizeof(ipbuf))) {
            continue;
        }
        if (ghbl_has_ip(stored, count, ipbuf)) {
            continue;
        }
        if (count >= GHBL_MAX_ADDRS) {
            break;
        }
        strncpy(stored[count], ipbuf, sizeof(stored[count]) - 1);
        stored[count][sizeof(stored[count]) - 1] = '\0';
        count++;
    }

    freeaddrinfo(res);

    if (0 == count) {
        return NULL;
    }

    ht = __hashtable__alloc();
    for (size_t i = 0; i < count; i++) {
        __hashtable__setStringAt(
            ht,
            i,
            __string__init((long long) strlen(stored[i]), stored[i])
        );
    }

    return ht;
}
