"""The only initial CLI recipe. Credentials arrive on stdin, never in argv/env/output."""
import json
import sys
import httpx


def probe(data):
    action, secret = data['action'], data['secret']
    with httpx.Client(proxy=data['proxy'], trust_env=False, follow_redirects=False, timeout=10) as client:
        response = client.get(action['url'], headers={'Authorization': 'Bearer ' + secret['token']})
        return response.status_code == 200


if __name__ == '__main__':
    try:
        success = probe(json.load(sys.stdin))
    except Exception:
        success = False
    sys.exit(0 if success else 1)
