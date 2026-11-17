"""Bounded, authenticated server benchmark. Never changes business data."""
from __future__ import annotations

import argparse
import asyncio
import concurrent.futures
import datetime as dt
import gzip
import html
from http.cookiejar import CookieJar
import ipaddress
import json
import math
import os
from pathlib import Path
import re
import ssl
import statistics
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

LOGINS = ('manager', 'logist', 'dispatcher', 'head', 'admin', 'superadmin')
TIMEOUT = 30
MAX_BODY = 20 * 1024 * 1024


class BenchmarkFailure(Exception):
    """Only static/sanitized messages may cross the CLI boundary."""


def origin(url: str) -> str:
    parts = urllib.parse.urlsplit(url)
    port = parts.port
    host = parts.hostname or ''
    if ':' in host:
        host = '[' + host + ']'
    default = 443 if parts.scheme in ('https', 'wss') else 80
    return parts.scheme + '://' + host + (':' + str(port) if port and port != default else '')


def validate_base(value: str) -> str:
    try:
        parts = urllib.parse.urlsplit(value)
        if (parts.scheme not in ('http', 'https') or not parts.hostname or parts.username or
                parts.password or parts.query or parts.fragment or parts.path not in ('', '/')):
            raise ValueError()
        return origin(value)
    except ValueError:
        raise BenchmarkFailure('Base URL must be an HTTP(S) root origin without credentials.') from None


def is_loopback(url: str) -> bool:
    host = urllib.parse.urlsplit(url).hostname
    if host == 'localhost':
        return True
    try:
        return ipaddress.ip_address(host or '').is_loopback
    except ValueError:
        return False


class SameOriginRedirect(urllib.request.HTTPRedirectHandler):
    def __init__(self, expected_origin: str):
        self.expected_origin = expected_origin

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if origin(newurl) != self.expected_origin:
            raise BenchmarkFailure('Unexpected cross-origin redirect; benchmark stopped.')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def password_from_file(path: Path) -> str:
    try:
        if path.stat().st_size > 16384:
            raise ValueError()
        content = path.read_text(encoding='utf-8-sig')
    except (OSError, UnicodeError, ValueError):
        raise BenchmarkFailure('Credential file is unavailable or invalid.') from None
    marker = 'Пароль для демоаккаунтов:'
    lines = content.splitlines()
    for line in lines:
        if line.strip().startswith(marker):
            value = line.strip()[len(marker):].strip()
            break
    else:
        if len(lines) != 1:
            raise BenchmarkFailure('Credential file must contain one password line or the demo-access format.')
        value = lines[0]
    if not value or len(value) > 256:
        raise BenchmarkFailure('Credential file contains an invalid password.')
    return value


def meta_value(page: str, name: str) -> str:
    # The application emits double-quoted, escaped meta attributes.
    match = re.search(r'<meta\b[^>]*\b(?:name|id)="' + re.escape(name) + r'"[^>]*\bcontent="([^"]*)"', page)
    if not match:
        raise BenchmarkFailure('Expected application metadata is missing.')
    return html.unescape(match.group(1))


class Session:
    def __init__(self, index: int, base: str, password: str, no_proxy: bool):
        self.index, self.base, self.password = index, base, password
        self.csrf = ''
        self.manifest: list[int] = []
        handlers = [urllib.request.HTTPCookieProcessor(CookieJar()), SameOriginRedirect(base)]
        if no_proxy or is_loopback(base):
            handlers.append(urllib.request.ProxyHandler({}))
        self.opener = urllib.request.build_opener(*handlers)

    def request(self, path: str, phase: str, body=None):
        headers = {'Accept-Encoding': 'gzip', 'User-Agent': 'DLTK-Bounded-Benchmark/1.0'}
        data = None
        if body is not None:
            data = json.dumps(body).encode('utf-8')
            headers.update({'Content-Type': 'application/json', 'Origin': self.base, 'X-CSRF-Token': self.csrf})
        req = urllib.request.Request(self.base + path, data=data, headers=headers)
        start = time.perf_counter()
        try:
            with self.opener.open(req, timeout=TIMEOUT) as response:
                headers_at = time.perf_counter()
                raw = response.read(MAX_BODY + 1)
                completed = time.perf_counter()
                encoding = response.headers.get('Content-Encoding', '').lower()
                if len(raw) > MAX_BODY:
                    raise BenchmarkFailure('Response exceeds the bounded benchmark limit.')
        except urllib.error.HTTPError as exc:
            raise BenchmarkFailure('HTTP ' + str(exc.code) + ' during ' + phase + '.') from None
        except BenchmarkFailure:
            raise
        except Exception:
            raise BenchmarkFailure('Connection failed during ' + phase + '.') from None
        try:
            decoded = gzip.decompress(raw) if encoding == 'gzip' else raw
            if len(decoded) > MAX_BODY:
                raise BenchmarkFailure('Decoded response exceeds the benchmark limit.')
        except (OSError, EOFError):
            raise BenchmarkFailure('Invalid compressed response during ' + phase + '.') from None
        return decoded, {
            'headers_ms': (headers_at - start) * 1000,
            'total_ms': (completed - start) * 1000,
            'wire_bytes': len(raw), 'decoded_bytes': len(decoded), 'gzip': encoding == 'gzip',
        }

    def setup(self):
        started = time.perf_counter()
        page, _ = self.request('/login', 'login page')
        self.csrf = meta_value(page.decode('utf-8'), 'csrf-token')
        result, _ = self.request('/login', 'login', {'login': LOGINS[self.index % len(LOGINS)], 'password': self.password})
        if json.loads(result).get('redirect') is None:
            raise BenchmarkFailure('Unexpected login response.')
        auth_ms = (time.perf_counter() - started) * 1000
        page, sample = self.request('/vitrine?table=vyvoz', 'initial grid')
        page = page.decode('utf-8')
        self.csrf = meta_value(page, 'csrf-token')
        self.manifest = json.loads(meta_value(page, 'vitrine-row-manifest'))
        if not isinstance(self.manifest, list) or any(type(i) is not int for i in self.manifest):
            raise BenchmarkFailure('Invalid row manifest.')
        return sample, auth_ms

    def bootstrap(self):
        body, sample = self.request('/api/workspace/bootstrap', 'bootstrap')
        parsed = json.loads(body)
        if not isinstance(parsed.get('tables'), list) or not parsed.get('user'):
            raise BenchmarkFailure('Invalid bootstrap response.')
        return sample

    def portion(self):
        ids = self.manifest[100:200] or self.manifest[:100]
        if not ids:
            return None
        body, sample = self.request('/api/vitrine/rows?table=vyvoz&ids=' + ','.join(map(str, ids)), 'row portion')
        parsed = json.loads(body)
        if not isinstance(parsed.get('html'), str) or not isinstance(parsed.get('rowIds'), list):
            raise BenchmarkFailure('Invalid row portion response.')
        sample['requested_rows'] = len(ids)
        sample['returned_rows'] = len(parsed['rowIds'])
        return sample

    def ticket(self):
        body, _ = self.request('/api/workspace/ws-ticket', 'WebSocket ticket', {})
        ticket = json.loads(body)
        parts = urllib.parse.urlsplit(ticket.get('url', ''))
        base_parts = urllib.parse.urlsplit(self.base)
        if (parts.scheme not in ('ws', 'wss') or parts.hostname != base_parts.hostname or
                parts.username or parts.password or parts.query or parts.fragment or
                (base_parts.scheme == 'https' and parts.scheme != 'wss')):
            raise BenchmarkFailure('Unexpected WebSocket destination.')
        return ticket


def distribution(values):
    ordered = sorted(values)
    if not ordered:
        return None
    return {key: round(value, 3) for key, value in {
        'min': ordered[0], 'p50': statistics.median(ordered),
        'p95': ordered[max(0, math.ceil(len(ordered) * .95) - 1)],
        'max': ordered[-1], 'mean': statistics.mean(ordered),
    }.items()}


def summarize(samples):
    samples = [item for item in samples if item is not None]
    if not samples:
        return {'requests': 0, 'skipped': 'No rows available.'}
    result = {
        'requests': len(samples),
        'time_to_headers_ms': distribution([s['headers_ms'] for s in samples]),
        'total_body_read_ms': distribution([s['total_ms'] for s in samples]),
        'wire_body_bytes': {'sum': sum(s['wire_bytes'] for s in samples), 'min': min(s['wire_bytes'] for s in samples), 'max': max(s['wire_bytes'] for s in samples)},
        'decoded_body_bytes': {'sum': sum(s['decoded_bytes'] for s in samples), 'min': min(s['decoded_bytes'] for s in samples), 'max': max(s['decoded_bytes'] for s in samples)},
        'gzip_responses': sum(s['gzip'] for s in samples),
    }
    if 'requested_rows' in samples[0]:
        result['rows_per_request'] = {'requested_min': min(s['requested_rows'] for s in samples), 'requested_max': max(s['requested_rows'] for s in samples), 'returned_min': min(s['returned_rows'] for s in samples), 'returned_max': max(s['returned_rows'] for s in samples)}
    return result


async def websocket_phase(sessions, tickets, no_proxy, hold):
    try:
        from websockets.asyncio.client import connect
    except ImportError:
        raise BenchmarkFailure('WebSocket option requires websockets==15.0.1.') from None
    ready_count = 0
    barrier = asyncio.Event()

    async def one(session, ticket):
        nonlocal ready_count
        options = {'origin': session.base, 'open_timeout': TIMEOUT, 'max_size': MAX_BODY}
        if no_proxy or is_loopback(session.base):
            options['proxy'] = None
        if ticket['url'].startswith('wss://'):
            options['ssl'] = ssl.create_default_context()
        start = time.perf_counter()
        try:
            async with connect(ticket['url'], **options) as ws:
                await ws.send(json.dumps({'type': 'auth', 'ticket': ticket['ticket'], 'cursor': ticket['cursor']}))
                while True:
                    item = json.loads(await asyncio.wait_for(ws.recv(), TIMEOUT))
                    if item.get('type') == 'ready':
                        break
                elapsed = (time.perf_counter() - start) * 1000
                ready_count += 1
                if ready_count == len(sessions):
                    barrier.set()
                await asyncio.wait_for(barrier.wait(), TIMEOUT)
                await asyncio.sleep(hold)
                return elapsed
        except Exception:
            barrier.set()
            raise BenchmarkFailure('Authenticated WebSocket handshake failed.') from None

    tasks = [asyncio.create_task(one(s, t)) for s, t in zip(sessions, tickets)]
    try:
        samples = await asyncio.wait_for(asyncio.gather(*tasks), TIMEOUT + hold + 5)
    except Exception:
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
        raise BenchmarkFailure('WebSocket phase did not complete successfully.') from None
    return {'connections': len(samples), 'authenticated_ready_ms': distribution(samples), 'simultaneous_hold_seconds': hold, 'business_edits_sent': 0}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--workers', type=int, default=15)
    parser.add_argument('--rounds', type=int, default=1)
    parser.add_argument('--password-file', type=Path)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--websocket', action='store_true')
    parser.add_argument('--ws-hold-seconds', type=float, default=2)
    parser.add_argument('--no-proxy', action='store_true')
    args = parser.parse_args()
    if not 1 <= args.workers <= 30 or not 1 <= args.rounds <= 5 or not 0 <= args.ws_hold_seconds <= 10:
        parser.error('workers must be1..30, rounds1..5 and ws-hold-seconds0..10')
    try:
        base = validate_base(args.base_url)
        password = password_from_file(args.password_file) if args.password_file else os.environ.get('DLTK_BENCH_PASSWORD', '')
        if not password or len(password) > 256:
            raise BenchmarkFailure('Use --password-file or DLTK_BENCH_PASSWORD; no password argv option is supported.')
        sessions = [Session(i, base, password, args.no_proxy) for i in range(args.workers)]
        with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
            setup = list(pool.map(lambda s: s.setup(), sessions))
            bootstrap, portions = [], []
            for _ in range(args.rounds):
                bootstrap.extend(pool.map(lambda s: s.bootstrap(), sessions))
                portions.extend(pool.map(lambda s: s.portion(), sessions))
            tickets = list(pool.map(lambda s: s.ticket(), sessions)) if args.websocket else None
        report = {
            'status': 'ok', 'atUtc': dt.datetime.now(dt.timezone.utc).isoformat(), 'baseUrl': base,
            'workers': args.workers, 'independent_cookie_sessions': len(sessions), 'rounds': args.rounds,
            'account_count': min(args.workers, len(LOGINS)),
            'proxy_mode': 'direct' if args.no_proxy or is_loopback(base) else 'environment',
            'authentication_setup_ms': distribution([result[1] for result in setup]),
            'initial_grid': summarize([result[0] for result in setup]),
            'bootstrap': summarize(bootstrap), 'row_portion': summarize(portions),
            'manifest_row_count': {'min': min(len(s.manifest) for s in sessions), 'max': max(len(s.manifest) for s in sessions)},
            'websocket': asyncio.run(websocket_phase(sessions, tickets, args.no_proxy, args.ws_hold_seconds)) if tickets else {'skipped': 'Use --websocket to test authenticated concurrent connections.'},
            'scope': {
                'business_data_writes': False, 'browser_paint_measured': False, 'sla_certification': False,
                'notes': [
                    'Authentication creates normal sessions/login bookkeeping; optional WS creates short-lived tickets only.',
                    'urllib HTTP/1.1 clients, not a browser or HTTP/2 benchmark.',
                    'Time to complete response headers is a TTFB proxy; body-read duration excludes local decompression/JSON parsing.',
                    'p95 uses nearest rank; small samples and a bounded burst are not long-duration load certification.',
                ],
            },
        }
    except BenchmarkFailure as exc:
        report = {'status': 'failed', 'message': str(exc), 'business_data_writes': False}
    except Exception as exc:
        report = {'status': 'failed', 'message': 'Benchmark failed: ' + type(exc).__name__, 'business_data_writes': False}
    encoded = json.dumps(report, ensure_ascii=False, indent=2)
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(encoded + '\n', encoding='utf-8')
    print(encoded)
    return 0 if report['status'] == 'ok' else 1


if __name__ == '__main__':
    raise SystemExit(main())
