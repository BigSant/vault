"""Best-effort check that a value does not carry an entry value. Never a guarantee: a page (or the site itself) can
encode a value in ways no filter recognises. Nothing here logs or returns a value.

What it recognises, exactly (docs/integrations.md repeats this list):

- each input on its own, and up to MAX_DEPTH layers of: reversal, percent-decoding, '+'-as-space percent-decoding,
  JSON (every string, key and value of a JSON array or object), base64 (standard and URL-safe alphabet, with or
  without padding, at every one of the four alignments inside a token of 4 characters or more, a dangling last
  character trimmed; a whole input of 2 or 3 characters, which is what one or two bytes encode to, as well) and hex
  (any run of 4 or more hex digits, odd or even, at both nibble alignments);
- a decoded byte string that is not UTF-8 is searched as it is (invalid bytes dropped) and only its printable ASCII
  runs of at least 8 characters are decoded further;
- a value equal to a candidate (any length; a value of one or two characters is matched only this way);
- a value of 3 to 5 characters as a whole word of a candidate: not directly next to a letter or digit (so a short
  PIN is not found by chance inside a long hex or base64 session token);
- a value of 6 characters or more contained in a candidate, or its reversal contained in one;
- a value of 8 characters or more cut once into two parts of at least 4, where one candidate (or one of its tokens,
  split at whitespace and & ; : , | = ? # / . " ') ends with the first part and another begins with the second.

Every input gets its own budget (candidates and bytes, proportional to its length). When an input exhausts it the
answer is True: an input that cannot be checked completely counts as carrying a value (fail closed).
"""
import base64
import binascii
import json
import re
from urllib.parse import unquote, unquote_plus

MAX_DEPTH = 4
# Per input: at least this many candidates / bytes, more for a longer input. Reaching it refuses.
MIN_CANDIDATES = 4096
CANDIDATES_PER_CHAR = 16
MIN_BYTES = 1 << 20
BYTES_PER_CHAR = 64
# A value shorter than MIN_WORD is only matched as a whole candidate; one shorter than MIN_SUBSTRING only as a whole
# word (bounded by anything but a letter or digit); a longer one anywhere.
MIN_WORD = 3
MIN_SUBSTRING = 6
MIN_SPLIT = 8
MIN_PART = 4
BASE64 = re.compile(r'[A-Za-z0-9+/_-]{4,}={0,2}')
# What one or two bytes encode to: found only as a whole input (a 2-3 character token inside a text is everywhere).
SHORT_BASE64 = re.compile(r'[A-Za-z0-9+/_-]{2,3}={0,2}')
HEX = re.compile(r'[0-9a-fA-F]{4,}')
PRINTABLE_RUN = re.compile(r'[\x20-\x7e]{8,}')
SEPARATORS = re.compile(r'''[\s&;:,|=?#/."']+''')


class Unchecked(Exception):
    """An input exhausted its budget; it cannot be said not to carry a value."""


def _from_bytes(raw):
    """(texts to decode further, texts only to search) for a decoded byte string."""
    try:
        return [raw.decode('utf-8')], []
    except UnicodeDecodeError:
        pass
    return PRINTABLE_RUN.findall(raw.decode('latin-1')), [raw.decode('utf-8', 'ignore')]


def _base64(core):
    for offset in range(min(4, max(1, len(core) - 1))):
        part = core[offset:]
        if len(part) % 4 == 1:
            # A dangling character (base64(value) + "X") cannot be decoded: trim it instead of skipping.
            part = part[:-1]
        if len(part) < 2:
            continue
        for alphabet in (None, b'-_'):
            try:
                yield base64.b64decode(part + '=' * (-len(part) % 4), altchars=alphabet)
            except (binascii.Error, ValueError):
                continue


def _decodings(text):
    """(strings one decoding step away from text, strings only to search)."""
    deeper, leaves = {text[::-1], unquote(text), unquote_plus(text)}, set()
    try:
        decoded = json.loads(text)
    except (ValueError, RecursionError):
        decoded = None
    if isinstance(decoded, (dict, list, str)):
        stack = [decoded]
        while stack:
            item = stack.pop()
            if isinstance(item, str):
                deeper.add(item)
            elif isinstance(item, dict):
                stack.extend(item.keys())
                stack.extend(item.values())
            elif isinstance(item, list):
                stack.extend(item)
    matches = BASE64.findall(text)
    if SHORT_BASE64.fullmatch(text):
        matches.append(text)
    for match in matches:
        for raw in _base64(match.rstrip('=')):
            more, only = _from_bytes(raw)
            deeper.update(more)
            leaves.update(only)
    for match in HEX.findall(text):
        for offset in (0, 1):
            part = match[offset:]
            part = part[:len(part) - len(part) % 2]
            try:
                more, only = _from_bytes(bytes.fromhex(part))
            except ValueError:
                continue
            deeper.update(more)
            leaves.update(only)
    deeper.discard(text)
    return deeper, leaves


def candidates(text):
    """The input, its reversal and what repeated decoding makes of them, case-folded. Raises Unchecked when the
    input's own budget is exhausted."""
    limit = MIN_CANDIDATES + CANDIDATES_PER_CHAR * len(text)
    budget = MIN_BYTES + BYTES_PER_CHAR * len(text)
    seen, frontier = set(), [text]
    for depth in range(MAX_DEPTH + 1):
        nxt = []
        for item in frontier:
            if item in seen:
                continue
            seen.add(item)
            budget -= len(item)
            if len(seen) > limit or budget < 0:
                raise Unchecked()
            if depth < MAX_DEPTH:
                deeper, leaves = _decodings(item)
                nxt.extend(deeper)
                for leaf in leaves:
                    if leaf not in seen:
                        seen.add(leaf)
                        budget -= len(leaf)
                # Checked here, not only before the next level: leaves of the last level count too.
                if len(seen) > limit or budget < 0:
                    raise Unchecked()
        frontier = nxt
        if not frontier:
            break
    return {item.casefold() for item in seen if item}


def _edges(found):
    """{first 4 characters: candidates/tokens}, {last 4 characters: candidates/tokens}."""
    starts, ends = {}, {}
    for item in found:
        for token in {item, *SEPARATORS.split(item)}:
            if len(token) >= MIN_PART:
                starts.setdefault(token[:MIN_PART], set()).add(token)
                ends.setdefault(token[-MIN_PART:], set()).add(token)
    return starts, ends


def carries(texts, secrets):
    """True when any of texts (or its decodings) carries a secret (see the module docstring), or when an input could
    not be checked completely."""
    values = [s.casefold() for s in secrets if isinstance(s, str) and s]
    if not values:
        return False
    found = set()
    try:
        for text in texts:
            if isinstance(text, str):
                found |= candidates(text)
    except Unchecked:
        return True
    for value in values:
        if value in found:
            return True
        if len(value) >= MIN_SUBSTRING and any(value in item or value[::-1] in item for item in found):
            return True
        if MIN_WORD <= len(value) < MIN_SUBSTRING:
            word = re.compile('|'.join(r'(?<![^\W_])' + re.escape(v) + r'(?![^\W_])' for v in {value, value[::-1]}))
            if any(word.search(item) for item in found):
                return True
    if any(len(value) >= MIN_SPLIT for value in values):
        starts, ends = _edges(found)
        for value in values:
            for cut in range(MIN_PART, len(value) - MIN_PART + 1):
                first, second = value[:cut], value[cut:]
                if any(t.endswith(first) for t in ends.get(first[-MIN_PART:], ())) and \
                        any(t.startswith(second) for t in starts.get(second[:MIN_PART], ())):
                    return True
    return False
