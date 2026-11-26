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
# writes a pattern. `digits:N` and `hex:N` bound the length (digits: 1..N, hex: exactly N).
SHAPES = {
    'digits': (r'[0-9]{1,%d}', 1, 20),
    'hex': (r'[0-9a-f]{%d}', 8, 64),
    'version': (r'[0-9]{1,6}(?:\.[0-9]{1,6}){0,3}', None, None),
}
NAME = re.compile(r'[A-Za-z_][A-Za-z0-9_]{0,31}')
PATH_PREFIX = re.compile(r'/(?:[A-Za-z0-9._-]+/)*')


def origin(url):
    parsed=urlsplit(url)
    schemes=('http','https') if os.environ.get('VAULT_TEST_FIXTURES')=='1' else ('https',)
    if parsed.scheme not in schemes or not parsed.hostname or parsed.username or parsed.password:
        return None
    return parsed.scheme,parsed.hostname,parsed.port or (443 if parsed.scheme=='https' else 80)


def registered(action):
    return {origin(action['url']),*(origin(value) for value in action.get('origins',[]))}-{None}


def shape(spec):
    """A compiled shape for 'digits', 'digits:13', 'hex:32' or 'version'; None for anything else."""
    if not isinstance(spec,str):return None
    name,_,size=spec.partition(':')
    if name not in SHAPES:return None
    pattern,low,high=SHAPES[name]
    if low is None:
        return re.compile(pattern) if not size else None
    if not size:
        if name=='hex':return None
        size=str(high)
    if not size.isdigit() or not low<=int(size)<=high:return None
    return re.compile(pattern%int(size))


def shapes(spec):
    """{parameter: compiled shape} for a `variable_query` / `static_query` object; None when malformed."""
    if not isinstance(spec,dict) or not spec:return None
    compiled={}
    for key,value in spec.items():
        pattern=shape(value)
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


def request_allowed(action,url,method):
    target=origin(url)
    if target is None or target not in registered(action):return False
    rules=[{'method':'GET','url':action['url']},*action.get('allowed_requests',[])]
    return any(isinstance(rule,dict) and rule_matches(rule,url,method) for rule in rules)


def static_config(action):
    """(path prefixes, extensions, query shapes) when the connector configured static paths validly, else None."""
    paths=action.get('static_paths')
    if not isinstance(paths,list) or not paths or not all(isinstance(p,str) and PATH_PREFIX.fullmatch(p) and '/../' not in p+'/' and '/./' not in p+'/' for p in paths):
        return None
    extensions=action.get('static_extensions',sorted(STATIC_EXTENSIONS))
    if not isinstance(extensions,list) or not extensions or not all(isinstance(e,str) and e in STATIC_EXTENSIONS for e in extensions):
        return None
    query=action.get('static_query')
    query={} if query is None else shapes(query)
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
    if '%' in path or '//' in path or len(path)>512:return False
    if not any(path.startswith(prefix) for prefix in paths):return False
    if not any(path.lower().endswith(extension) for extension in extensions):return False
    return query_matches('',parsed.query,query)
