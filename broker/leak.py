"""Best-effort check that a value does not carry an entry value. Never a guarantee: a page (or the site itself) can
encode a value in ways no filter recognises. It decodes what it sees (percent, '+', JSON, base64, hex, reversal,
repeatedly) and looks for the value, also split in two across the inputs. Nothing here logs or returns a value."""
import base64
import binascii
import json
import re
from urllib.parse import unquote, unquote_plus

MAX_DEPTH = 4
MAX_CANDIDATES = 4096
BASE64 = re.compile(r'[A-Za-z0-9+/_-]{4,}={0,2}')
HEX = re.compile(r'(?:[0-9a-fA-F]{2}){2,}')


def _decodings(text):
    """Strings one decoding step away from text."""
    out = {unquote(text), unquote_plus(text), text[::-1]}
    try:
        decoded = json.loads(text)
    except (ValueError, RecursionError):
        decoded = None
    if decoded is not None and not isinstance(decoded, (int, float, bool)):
        stack = [decoded]
        while stack and len(out) < MAX_CANDIDATES:
            item = stack.pop()
            if isinstance(item, str):
                out.add(item)
            elif isinstance(item, dict):
                stack.extend(item.keys())
                stack.extend(item.values())
            elif isinstance(item, list):
                stack.extend(item)
    for match in BASE64.findall(text):
        core = match.rstrip('=')
        # Every alignment: a base64 value may sit inside a longer token.
        for offset in range(min(4, len(core) - 3)):
            part = core[offset:]
            for alphabet in (None, b'-_'):
                try:
                    raw = base64.b64decode(part + '=' * (-len(part) % 4), altchars=alphabet)
                except (binascii.Error, ValueError):
                    continue
                out.add(raw.decode('utf-8', 'ignore'))
                out.add(raw.decode('latin-1'))
    for match in HEX.findall(text):
        for offset in (0, 1):
            part = match[offset:]
            part = part[:len(part) - len(part) % 2]
            try:
                out.add(bytes.fromhex(part).decode('utf-8', 'ignore'))
            except ValueError:
                pass
    out.discard(text)
    return out


def candidates(texts):
    """Every input and what repeated decoding makes of it, case-folded. Bounded in depth and count."""
    seen = set()
    frontier = [t for t in texts if isinstance(t, str)]
    for _ in range(MAX_DEPTH + 1):
        nxt = []
        for text in frontier:
            if text in seen or len(seen) >= MAX_CANDIDATES:
                continue
            seen.add(text)
            nxt.extend(_decodings(text))
        frontier = nxt
        if not frontier:
            break
    return {text.casefold() for text in seen if text}


def carries(texts, secrets):
    """True when any of texts (or its decodings) contains a secret, its reversal, or both halves of it split in two."""
    found = candidates(texts)
    joined = '\n'.join(sorted(found))
    for secret in secrets:
        if not isinstance(secret, str) or not secret:
            continue
        value = secret.casefold()
        if value in found or (len(value) >= 4 and (value in joined or value[::-1] in joined)):
            return True
        if len(value) >= 8:
            for cut in range(4, len(value) - 3):
                if value[:cut] in joined and value[cut:] in joined:
                    return True
    return False
