"""Exact operator-owned browser request policy, independent of page content."""
import os
from urllib.parse import urlsplit, urldefrag


def origin(url):
    parsed=urlsplit(url)
    schemes=('http','https') if os.environ.get('VAULT_TEST_FIXTURES')=='1' else ('https',)
    if parsed.scheme not in schemes or not parsed.hostname or parsed.username or parsed.password:
        return None
    return parsed.scheme,parsed.hostname,parsed.port or (443 if parsed.scheme=='https' else 80)


def request_allowed(action,url,method):
    target=origin(url)
    allowed={origin(action['url']),*(origin(value) for value in action.get('origins',[]))}
    if target is None or target not in allowed:return False
    rules=[{'method':'GET','url':action['url']},*action.get('allowed_requests',[])]
    return any(rule.get('method')==method and urldefrag(rule.get('url',''))[0]==urldefrag(url)[0] for rule in rules)
