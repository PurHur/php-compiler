/*
 * getprotobynumber() / getservbyport() runtime for JIT/AOT (issue #3650).
 * php-src: ext/standard/network.c
 */

#include <arpa/inet.h>
#include <ctype.h>
#include <netdb.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __value__ __value__;

extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeBool(__value__ *out, int value);
extern void __value__writeLong(__value__ *out, long long v);
extern void __compiler_trigger_error(const char *message, size_t len, int level);

static const char *NS_FALLBACK_PROTOCOLS = "/compiler/ext/standard/data/protocols";
static const char *NS_FALLBACK_SERVICES = "/compiler/ext/standard/data/services";

#define NS_ERR_LEVEL_WARNING 2

static int ns_is_space(int c)
{
    return ' ' == c || '\t' == c || '\r' == c || '\n' == c;
}

static void ns_trim_line(char *line)
{
    size_t len = strlen(line);

    while (len > 0 && ns_is_space((unsigned char) line[len - 1])) {
        line[--len] = '\0';
    }
    size_t start = 0;
    while (line[start] != '\0' && ns_is_space((unsigned char) line[start])) {
        start++;
    }
    if (start > 0) {
        memmove(line, line + start, strlen(line + start) + 1);
    }
}

static __string__ *ns_name_from_file(const char *path, int (*match)(const char *name, const char *token, void *ctx), void *ctx)
{
    FILE *fp = fopen(path, "r");

    if (NULL == fp) {
        return NULL;
    }
    char line[512];
    while (fgets(line, sizeof(line), fp) != NULL) {
        char *hash = strchr(line, '#');

        if (NULL != hash) {
            *hash = '\0';
        }
        ns_trim_line(line);
        if ('\0' == line[0]) {
            continue;
        }
        char *name = strtok(line, " \t");
        char *token = strtok(NULL, " \t");

        if (NULL == name || NULL == token) {
            continue;
        }
        if (match(name, token, ctx)) {
            fclose(fp);

            return __string__init((long long) strlen(name), name);
        }
    }
    fclose(fp);

    return NULL;
}

static int ns_match_proto_number(const char *name, const char *token, void *ctx)
{
    int want = *((int *) ctx);
    long num;

    (void) name;
    num = strtol(token, NULL, 10);

    return (int) num == want;
}

static int ns_match_service_port(const char *name, const char *token, void *ctx)
{
    struct {
        int port;
        const char *proto;
    } *want = ctx;
    char *slash;
    long port;
    const char *proto;

    (void) name;
    slash = strchr(token, '/');
    if (NULL == slash) {
        return 0;
    }
    *slash = '\0';
    port = strtol(token, NULL, 10);
    proto = slash + 1;
    *slash = '/';
    if (port != want->port) {
        return 0;
    }

    return 0 == strcasecmp(proto, want->proto);
}

static __string__ *ns_lookup_protocol(int number)
{
    const char *paths[] = {"/etc/protocols", NS_FALLBACK_PROTOCOLS, NULL};
    size_t i;

    for (i = 0; paths[i] != NULL; i++) {
        __string__ *found = ns_name_from_file(paths[i], ns_match_proto_number, &number);

        if (NULL != found) {
            return found;
        }
    }

    return NULL;
}

static int ns_is_token_equal_ci(const char *token, const char *want)
{
    return 0 == strcasecmp(token, want);
}

static long long ns_lookup_protocol_by_name(const char *want)
{
    const char *paths[] = {"/etc/protocols", NS_FALLBACK_PROTOCOLS, NULL};
    size_t i;

    for (i = 0; paths[i] != NULL; i++) {
        FILE *fp = fopen(paths[i], "r");

        if (NULL == fp) {
            continue;
        }
        char line[512];
        while (fgets(line, sizeof(line), fp) != NULL) {
            char *hash = strchr(line, '#');

            if (NULL != hash) {
                *hash = '\0';
            }
            ns_trim_line(line);
            if ('\0' == line[0]) {
                continue;
            }
            char *name = strtok(line, " \t");
            char *numToken = strtok(NULL, " \t");

            if (NULL == name || NULL == numToken) {
                continue;
            }
            if (ns_is_token_equal_ci(name, want)) {
                fclose(fp);

                return strtoll(numToken, NULL, 10);
            }
            char *alias;
            while ((alias = strtok(NULL, " \t")) != NULL) {
                if (ns_is_token_equal_ci(alias, want)) {
                    fclose(fp);

                    return strtoll(numToken, NULL, 10);
                }
            }
        }
        fclose(fp);
    }

    return -1;
}

static long long ns_lookup_service_by_name(const char *service, const char *proto)
{
    const char *paths[] = {"/etc/services", NS_FALLBACK_SERVICES, NULL};
    size_t i;

    for (i = 0; paths[i] != NULL; i++) {
        FILE *fp = fopen(paths[i], "r");

        if (NULL == fp) {
            continue;
        }
        char line[512];
        while (fgets(line, sizeof(line), fp) != NULL) {
            char *hash = strchr(line, '#');

            if (NULL != hash) {
                *hash = '\0';
            }
            ns_trim_line(line);
            if ('\0' == line[0]) {
                continue;
            }
            char *name = strtok(line, " \t");
            char *portProto = strtok(NULL, " \t");

            if (NULL == name || NULL == portProto) {
                continue;
            }
            char *slash = strchr(portProto, '/');
            if (NULL == slash) {
                continue;
            }
            *slash = '\0';
            const char *filePortStr = portProto;
            const char *fileProto = slash + 1;

            if (0 != strcasecmp(fileProto, proto)) {
                *slash = '/';
                continue;
            }
            long long port = strtoll(filePortStr, NULL, 10);
            *slash = '/';

            if (ns_is_token_equal_ci(name, service)) {
                fclose(fp);

                return port;
            }
            char *alias;
            while ((alias = strtok(NULL, " \t")) != NULL) {
                if (ns_is_token_equal_ci(alias, service)) {
                    fclose(fp);

                    return port;
                }
            }
        }
        fclose(fp);
    }

    return -1;
}

static __string__ *ns_lookup_service(int port, const char *proto)
{
    struct {
        int port;
        const char *proto;
    } ctx = {port, proto};
    const char *paths[] = {"/etc/services", NS_FALLBACK_SERVICES, NULL};
    size_t i;

    for (i = 0; paths[i] != NULL; i++) {
        __string__ *found = ns_name_from_file(paths[i], ns_match_service_port, &ctx);

        if (NULL != found) {
            return found;
        }
    }

    return NULL;
}

__string__ *__compiler_getprotobynumber(long long number)
{
    struct protoent *ent = getprotobynumber((int) number);

    if (NULL != ent && NULL != ent->p_name && '\0' != ent->p_name[0]) {
        return __string__init((long long) strlen(ent->p_name), ent->p_name);
    }

    return ns_lookup_protocol((int) number);
}

long long __compiler_getprotobyname(__string__ *name)
{
    const char *p;
    size_t len;
    char buf[128];
    struct protoent *ent;

    if (NULL == name) {
        __compiler_trigger_error("getprotobyname(): Protocol name not found", 41, NS_ERR_LEVEL_WARNING);

        return -1;
    }
    len = (size_t) *((long long *) ((char *) name + sizeof(void *)));
    p = (const char *) name + sizeof(void *) + sizeof(long long);
    if (0 == len || len >= sizeof(buf)) {
        __compiler_trigger_error("getprotobyname(): Protocol name not found", 41, NS_ERR_LEVEL_WARNING);

        return -1;
    }
    memcpy(buf, p, len);
    buf[len] = '\0';

    ent = getprotobyname(buf);
    if (NULL != ent) {
        return (long long) ent->p_proto;
    }

    long long fallback = ns_lookup_protocol_by_name(buf);
    if (fallback >= 0) {
        return fallback;
    }

    __compiler_trigger_error("getprotobyname(): Protocol name not found", 41, NS_ERR_LEVEL_WARNING);

    return -1;
}

__string__ *__compiler_getservbyport(long long port, __string__ *protocol)
{
    const char *proto;
    struct servent *ent;
    size_t plen;
    char proto_buf[64];

    if (NULL == protocol) {
        return NULL;
    }
    plen = (size_t) *((long long *) ((char *) protocol + sizeof(void *)));
    proto = (const char *) protocol + sizeof(void *) + sizeof(long long);
    if (0 == plen || plen >= sizeof(proto_buf)) {
        return NULL;
    }
    memcpy(proto_buf, proto, plen);
    proto_buf[plen] = '\0';

    ent = getservbyport(htons((unsigned short) port), proto_buf);
    if (NULL != ent && NULL != ent->s_name && '\0' != ent->s_name[0]) {
        return __string__init((long long) strlen(ent->s_name), ent->s_name);
    }

    return ns_lookup_service((int) port, proto_buf);
}

long long __compiler_getservbyname(__string__ *service, __string__ *protocol)
{
    const char *s;
    size_t slen;
    char svc_buf[128];
    const char *p;
    size_t plen;
    char proto_buf[64];
    struct servent *ent;

    if (NULL == service || NULL == protocol) {
        __compiler_trigger_error("getservbyname(): Service name not found", 39, NS_ERR_LEVEL_WARNING);

        return -1;
    }
    slen = (size_t) *((long long *) ((char *) service + sizeof(void *)));
    s = (const char *) service + sizeof(void *) + sizeof(long long);
    if (0 == slen || slen >= sizeof(svc_buf)) {
        __compiler_trigger_error("getservbyname(): Service name not found", 39, NS_ERR_LEVEL_WARNING);

        return -1;
    }
    memcpy(svc_buf, s, slen);
    svc_buf[slen] = '\0';

    plen = (size_t) *((long long *) ((char *) protocol + sizeof(void *)));
    p = (const char *) protocol + sizeof(void *) + sizeof(long long);
    if (0 == plen || plen >= sizeof(proto_buf)) {
        __compiler_trigger_error("getservbyname(): Service name not found", 39, NS_ERR_LEVEL_WARNING);

        return -1;
    }
    memcpy(proto_buf, p, plen);
    proto_buf[plen] = '\0';

    ent = getservbyname(svc_buf, proto_buf);
    if (NULL != ent) {
        return (long long) ntohs((unsigned short) ent->s_port);
    }

    long long fallback = ns_lookup_service_by_name(svc_buf, proto_buf);
    if (fallback >= 0) {
        return fallback;
    }

    __compiler_trigger_error("getservbyname(): Service name not found", 39, NS_ERR_LEVEL_WARNING);

    return -1;
}

void __phpc_getprotobyname(__string__ *name, __value__ *out)
{
    long long result = __compiler_getprotobyname(name);

    if (result < 0) {
        __value__writeBool(out, 0);
    } else {
        __value__writeLong(out, result);
    }
}

void __phpc_getservbyname(__string__ *service, __string__ *protocol, __value__ *out)
{
    long long result = __compiler_getservbyname(service, protocol);

    if (result < 0) {
        __value__writeBool(out, 0);
    } else {
        __value__writeLong(out, result);
    }
}
