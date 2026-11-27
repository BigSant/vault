"""Checks a connectors.json for what a broker of this version refuses, before it is used: `bin/vault prepare` runs it
(the update step in docs/operations.md), the destination proxy shares its address rule. Messages name the connector
(an operator-chosen key), never a URL, selector or value."""
import ipaddress
from urllib.parse import urlsplit

from browser_policy import credential_config_valid

LOGIN_KINDS = ('browser', 'storage_state')


def address(url, schemes=('https',)):
    """(host, port) of a connector URL the destination proxy would allow, else None."""
    parsed = urlsplit(url if isinstance(url, str) else '')
    if parsed.scheme not in schemes or not parsed.hostname or parsed.username:
        return None
    return parsed.hostname, parsed.port or (443 if parsed.scheme == 'https' else 80)


def cidr_conflicts(actions, schemes=('https',)):
    """The (host, port) pairs that two connectors give different allow_cidrs."""
    seen, conflicts = {}, set()
    for action in actions.values():
        if not isinstance(action, dict):
            continue
        cidrs = frozenset(ipaddress.ip_network(c) for c in action.get('allow_cidrs', []))
        for url in [action.get('url', ''), *action.get('origins', [])]:
            key = address(url, schemes)
            if key is None:
                continue
            if key in seen and seen[key] != cidrs:
                conflicts.add(key)
            seen.setdefault(key, cidrs)
    return conflicts


def problems(actions, schemes=('https',)):
    """Fixed sentences, one per problem."""
    found = []
    for name, action in sorted(actions.items()):
        if not isinstance(action, dict):
            continue
        if 'static_subresources' in action:
            found.append(f'{name}: "static_subresources" is refused; use "static_paths" (docs/integrations.md)')
        if action.get('kind') in LOGIN_KINDS and not credential_config_valid(action):
            found.append(f'{name}: name the login request: exactly one POST rule in "allowed_requests" with "credential_fields" (1-8 form field names)')
    try:
        if cidr_conflicts(actions, schemes):
            found.append('two connectors give one host:port different "allow_cidrs"; the destination proxy refuses every destination until they agree')
    except ValueError:
        found.append('an "allow_cidrs" entry is not a network')
    return found
