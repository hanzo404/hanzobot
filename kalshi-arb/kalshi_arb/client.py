"""Kalshi v2 public API client (stdlib only, no API key needed for market data).

Docs: https://trading-api.kalshi.com  (v2 base: /trade-api/v2)
"""
from __future__ import annotations

import json
import time
import urllib.error
import urllib.parse
import urllib.request

from .config import Config
from .models import Market


class KalshiError(RuntimeError):
    pass


class KalshiClient:
    def __init__(self, cfg: Config) -> None:
        self.cfg = cfg
        self._last_request = 0.0

    # ------------------------------------------------------------------ http

    def _get(self, path: str, params: dict | None = None) -> dict:
        url = self.cfg.api_base.rstrip("/") + path
        if params:
            url += "?" + urllib.parse.urlencode(params)
        req = urllib.request.Request(url, headers={"User-Agent": "hanzobot-kalshi-arb/0.1"})
        deadline = 3
        while True:
            try:
                with urllib.request.urlopen(req, timeout=self.cfg.request_timeout_sec) as r:
                    return json.loads(r.read().decode())
            except urllib.error.HTTPError as e:
                if e.code == 429 and deadline > 0:
                    deadline -= 1
                    time.sleep(1.0)
                    continue
                body = e.read().decode(errors="replace")[:300]
                raise KalshiError(f"HTTP {e.code} for {url}: {body}") from e
            except (urllib.error.URLError, TimeoutError, OSError) as e:
                if deadline > 0:
                    deadline -= 1
                    time.sleep(1.0)
                    continue
                raise KalshiError(f"network error for {url}: {e}") from e

    # --------------------------------------------------------------- markets

    def open_markets_raw(self) -> list[dict]:
        """Fetch all open markets (raw API objects), paginating up to scan_pages."""
        out: list[dict] = []
        cursor = ""
        for _ in range(max(1, self.cfg.scan_pages)):
            params: dict = {"status": "open", "limit": str(self.cfg.scan_limit)}
            if cursor:
                params["cursor"] = cursor
            data = self._get("/markets", params)
            raw = data.get("markets") or []
            out.extend(raw)
            cursor = data.get("cursor") or ""
            if not cursor or len(raw) < self.cfg.scan_limit:
                break
        return out

    def open_markets(self) -> list[Market]:
        return [Market.from_api(d) for d in self.open_markets_raw()]

    def market(self, ticker: str) -> Market | None:
        try:
            data = self._get(f"/markets/{urllib.parse.quote(ticker)}")
        except KalshiError:
            return None
        raw = (data or {}).get("market")
        return Market.from_api(raw) if raw else None


class FixtureClient:
    """Offline stand-in for KalshiClient, fed from a saved API response.

    Used for tests and for demoing the pipeline in environments without
    direct access to the API host.
    """

    def __init__(self, markets: list[dict]) -> None:
        self._by_ticker = {d.get("ticker"): d for d in markets}
        self._markets = markets

    def open_markets_raw(self) -> list[dict]:
        return list(self._markets)

    def open_markets(self) -> list[Market]:
        return [Market.from_api(d) for d in self._markets]

    def market(self, ticker: str) -> Market | None:
        d = self._by_ticker.get(ticker)
        return Market.from_api(d) if d else None
