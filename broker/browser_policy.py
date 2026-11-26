"""Exact operator-owned browser request policy, independent of page content."""
import os
import re
from urllib.parse import parse_qsl, urlsplit, urldefrag

# Resource types a page loads without reading them back as data. With `static_subresources` a GET of one of these
# from a registered origin needs no allowed_requests entry; from anywhere else it is dropped before it leaves.
STATIC_TYPES = frozenset({'stylesheet', 'image', 'media', 'font', 'script', 'texttrack', 'manifest', 'other'})
# A value a `variable_query` parameter may take: short, no separators, so it can only carry a nonce or a token.
VARIABLE_VALUE = re.compile(r'[A-Za-z0-9_-]{1,64}')


def origin(url):
    parsed=urlsplit(url)
    schemes=('http','https') if os.environ.get('VAULT_TEST_FIXTURES')=='1' else ('https',)
    if parsed.scheme not in schemes or not parsed.hostname or parsed.username or parsed.password:
        return None
    return parsed.scheme,parsed.hostname,parsed.port or (443 if parsed.scheme=='https' else 80)


def registered(action):
    return {origin(action['url']),*(origin(value) for value in action.get('origins',[]))}-{None}


def rule_matches(rule,url,method):
    """The method and URL match exactly; a rule may name `variable_query` parameters whose values may differ (each at
    most once, VARIABLE_VALUE only). Every other query parameter must be exactly the rule's."""
    wanted=urldefrag(rule.get('url',''))[0];actual=urldefrag(url)[0]
    if rule.get('method')!=method:return False
    variable=rule.get('variable_query') or []
    if not variable:return wanted==actual
    if not isinstance(variable,list) or not all(isinstance(name,str) and name for name in variable):return False
    want,got=urlsplit(wanted),urlsplit(actual)
    if (want.scheme,want.netloc,want.path)!=(got.scheme,got.netloc,got.path):return False
    try:
        fixed=parse_qsl(want.query,keep_blank_values=True,strict_parsing=bool(want.query))
        pairs=parse_qsl(got.query,keep_blank_values=True,strict_parsing=bool(got.query))
    except ValueError:
        return False
    seen=set();rest=[]
    for key,value in pairs:
        if key in variable and key not in seen and VARIABLE_VALUE.fullmatch(value):
            seen.add(key);continue
        rest.append((key,value))
    return sorted(rest)==sorted(fixed)


def request_allowed(action,url,method):
    target=origin(url)
    if target is None or target not in registered(action):return False
    rules=[{'method':'GET','url':action['url']},*action.get('allowed_requests',[])]
    return any(isinstance(rule,dict) and rule_matches(rule,url,method) for rule in rules)


def static_request(action,method,resource_type,navigation):
    """A GET subresource of a static type, when the connector opted in. Never a document, frame, XHR/fetch,
    EventSource, WebSocket or anything but GET."""
    return action.get('static_subresources') is True and method=='GET' and not navigation and resource_type in STATIC_TYPES


def static_allowed(action,url,method,resource_type,navigation):
    return static_request(action,method,resource_type,navigation) and origin(url) in registered(action)
