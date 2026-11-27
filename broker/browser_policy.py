"""Exact operator-owned browser request policy, independent of page content."""
import os
import re
from urllib.parse import urlsplit, urldefrag

# Resource types a page loads without reading them back as data. Only with `static_paths` and only under one of
# those path prefixes may a GET of one of these leave without an allowed_requests entry; it is sent without cookies
# or credentials and its Set-Cookie is dropped. Everything else of these types is dropped before it leaves.
# "other" (beacons, prefetch, anything Chromium cannot classify) is never static.
STATIC_TYPES = frozenset({'stylesheet', 'image', 'media', 'font', 'script', 'texttrack', 'manifest'})
# File extensions a static path may end with; a connector's `static_extensions` may only narrow this list.
STATIC_EXTENSIONS = frozenset({'.css', '.js', '.mjs', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.avif',
                               '.ico', '.woff', '.woff2', '.ttf', '.otf', '.eot', '.vtt', '.webmanifest'})
# The shapes a `variable_query` / `static_query` parameter may have. A fixed vocabulary: an operator names one, never
# writes a pattern. `digits:N` and `hex:N` bound the length (digits: 1..N, hex: exactly N); `token:N` is 16..N
# base64url characters (a CSRF token such as Symfony's `_token`), N from 16 to 128, 64 when left out. A token can
# carry anything a page encodes into it: only the URL check (leak.py) stands between it and an encoded entry value.
SHAPES = {
    'digits': (r'[0-9]{1,%d}', 1, 20),
    'hex': (r'[0-9a-f]{%d}', 8, 64),
    'token': (r'[A-Za-z0-9_-]{16,%d}', 16, 128),
    'version': (r'[0-9]{1,6}(?:\.[0-9]{1,6}){0,3}', None, None),
}
# A static file's query (`static_query`) may only carry a number or a version (cache busting): never a hex or token
# value, which could carry far more of what a page chose.
STATIC_SHAPES = frozenset({'digits', 'version'})
NAME = re.compile(r'[A-Za-z_][A-Za-z0-9_]{0,31}')
# At least one segment: "/" alone would open the whole origin to static GETs.
PATH_PREFIX = re.compile(r'/(?:[A-Za-z0-9._-]+/)+')
DOT_SEGMENT = re.compile(r'(?:^|/)\.+(?:/|$)')


def origin(url):
    parsed=urlsplit(url)
    schemes=('http','https') if os.environ.get('VAULT_TEST_FIXTURES')=='1' else ('https',)
    if parsed.scheme not in schemes or not parsed.hostname or parsed.username or parsed.password:
        return None
    return parsed.scheme,parsed.hostname,parsed.port or (443 if parsed.scheme=='https' else 80)


def registered(action):
    return {origin(action['url']),*(origin(value) for value in action.get('origins',[]))}-{None}


def shape(spec,allowed=None):
    """A compiled shape for 'digits', 'digits:13', 'hex:32' or 'version'; None for anything else (or for a shape
    outside `allowed`, when given)."""
    if not isinstance(spec,str):return None
    name,_,size=spec.partition(':')
    if name not in SHAPES or allowed is not None and name not in allowed:return None
    pattern,low,high=SHAPES[name]
    if low is None:
        return re.compile(pattern) if not size else None
    if not size:
        if name=='hex':return None
        size=str(64 if name=='token' else high)
    if not size.isdigit() or not low<=int(size)<=high:return None
    return re.compile(pattern%int(size))


def shapes(spec,allowed=None):
    """{parameter: compiled shape} for a `variable_query` / `static_query` object; None when malformed."""
    if not isinstance(spec,dict) or not spec:return None
    compiled={}
    for key,value in spec.items():
        pattern=shape(value,allowed)
        if not isinstance(key,str) or not NAME.fullmatch(key) or pattern is None:return None
        compiled[key]=pattern
    return compiled


def raw_pairs(query):
    """The query's raw key/value pairs, undecoded. None when it is not a plain k=v&k=v list."""
    if not query:return []
    pairs=[]
    for part in query.split('&'):
        key,sep,value=part.partition('=')
        if not key or not sep:return None
        pairs.append((key,value))
    return pairs


def query_matches(fixed,actual,variable):
    """Every fixed pair exactly (raw); each variable parameter at most once, its raw value in its shape. A
    percent-encoded or '+' key or value never matches a variable parameter."""
    want,got=raw_pairs(fixed),raw_pairs(actual)
    if want is None or got is None:return False
    seen=set();rest=[]
    for key,value in got:
        if key in variable:
            if key in seen or not variable[key].fullmatch(value):return False
            seen.add(key);continue
        rest.append((key,value))
    return sorted(rest)==sorted(want)


def rule_matches(rule,url,method):
    """The method and URL match exactly; a rule may name `variable_query` parameters with a shape, whose values may
    differ (each at most once). Every other query parameter must be exactly the rule's."""
    wanted=urldefrag(rule.get('url',''))[0];actual=urldefrag(url)[0]
    if rule.get('method')!=method:return False
    if 'variable_query' not in rule:return wanted==actual
    variable=shapes(rule['variable_query'])
    if variable is None:return False
    want,got=urlsplit(wanted),urlsplit(actual)
    if (want.scheme,want.netloc,want.path)!=(got.scheme,got.netloc,got.path):return False
    return query_matches(want.query,got.query,variable)


def matching_rule(action,url,method):
    """The rule (the initial GET or an allowed_requests entry) that allows this request; None when none does. The
    first match wins, and a rule with `credential_fields` is preferred, so the login request is always recognised."""
    target=origin(url)
    if target is None or target not in registered(action):return None
    rules=[{'method':'GET','url':action['url']},*action.get('allowed_requests',[])]
    matches=[rule for rule in rules if isinstance(rule,dict) and rule_matches(rule,url,method)]
    return next((rule for rule in matches if rule.get('credential_fields')),matches[0] if matches else None)


def request_allowed(action,url,method):
    return matching_rule(action,url,method) is not None


# A form field that may carry the typed credentials: a plain HTML name.
FIELD=re.compile(r'[A-Za-z0-9_.\[\]-]{1,64}')


def credential_config_valid(action):
    """The login request is named explicitly: exactly one allowed_requests rule, a POST, with `credential_fields`
    (1-8 form field names). Every other request may carry no entry value in its headers or body."""
    rules=action.get('allowed_requests',[])
    if not isinstance(rules,list):return False
    named=[r for r in rules if isinstance(r,dict) and 'credential_fields' in r]
    if len(named)!=1:return False
    fields=named[0]['credential_fields']
    return named[0].get('method')=='POST' and isinstance(fields,list) and 1<=len(fields)<=8 and all(isinstance(f,str) and FIELD.fullmatch(f) for f in fields)


def ignored(action,url,method):
    """A request the connector's `ignore_requests` names: same method, scheme, host, port and path (any query). It is
    aborted before it leaves, without failing the login (a dashboard's notification poll, a wishlist fetch)."""
    rules=action.get('ignore_requests',[])
    if not isinstance(rules,list) or origin(url) not in registered(action):return False
    actual=urlsplit(urldefrag(url)[0])
    for rule in rules:
        if not isinstance(rule,dict) or rule.get('method')!=method or not isinstance(rule.get('url'),str):continue
        wanted=urlsplit(rule['url'])
        if wanted.query or wanted.fragment or origin(rule['url']) is None:continue
        if (wanted.scheme,wanted.netloc,wanted.path)==(actual.scheme,actual.netloc,actual.path):return True
    return False


def ignore_config_valid(action):
    """`ignore_requests`: absent, or a list of {method, url} with an absolute URL without query on a registered origin."""
    rules=action.get('ignore_requests',[])
    return isinstance(rules,list) and all(isinstance(r,dict) and set(r)=={'method','url'} and r['method'] in ('GET','POST','HEAD')
        and isinstance(r['url'],str) and origin(r['url']) in registered(action) and not urlsplit(r['url']).query and '#' not in r['url'] for r in rules)


def static_config(action):
    """(path prefixes, extensions, query shapes) when the connector configured static paths validly, else None."""
    paths=action.get('static_paths')
    if not isinstance(paths,list) or not paths or not all(isinstance(p,str) and PATH_PREFIX.fullmatch(p) and not DOT_SEGMENT.search(p) for p in paths):
        return None
    extensions=action.get('static_extensions',sorted(STATIC_EXTENSIONS))
    if not isinstance(extensions,list) or not extensions or not all(isinstance(e,str) and e in STATIC_EXTENSIONS for e in extensions):
        return None
    query=action.get('static_query')
    query={} if query is None else shapes(query,STATIC_SHAPES)
    if query is None:return None
    return tuple(paths),tuple(extensions),query


def static_request(action,method,resource_type,navigation):
    """A GET subresource of a static type, when the connector has static paths. Never a document, frame, XHR/fetch,
    EventSource, WebSocket, beacon or anything but GET."""
    return static_config(action) is not None and method=='GET' and not navigation and resource_type in STATIC_TYPES


def static_allowed(action,url,method,resource_type,navigation):
    """A static request to a registered origin, under one of `static_paths`, ending with an allowed extension, with
    no query but the `static_query` parameters in their shapes."""
    if not static_request(action,method,resource_type,navigation) or origin(url) not in registered(action):return False
    paths,extensions,query=static_config(action)
    parsed=urlsplit(urldefrag(url)[0])
    path=parsed.path
    if '%' in path or '//' in path or len(path)>512 or DOT_SEGMENT.search(path):return False
    if not any(path.startswith(prefix) for prefix in paths):return False
    if not any(path.lower().endswith(extension) for extension in extensions):return False
    return query_matches('',parsed.query,query)
