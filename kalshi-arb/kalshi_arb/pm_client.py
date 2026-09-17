"""Polymarket Gamma API client (public market data, no key).

Docs: https://docs.polymarket.com  (market data: gamma-api.polymarket.com)

Gamma returns a mix of string/number encodings (prices often as JSON-string
arrays), so parsing is deliberately defensive: if a field is missing or
unparseable the market is marked non-tradable rather than crashing.
"""
from __future__ import annotations

import json
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass

from .client import KalshiError  # reuse the error type
from .models import parse_ts


def _f(value, default: float = 0.0) -> float:
    if value in (None, ""):
        return default
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def _json_list(value, default: list) -> list:
    """Parse fields that arrive as either a list or a JSON string."""
    if isinstance(value, list):
        return value
    if isinstance(value, str):
        try:
            out = json.loads(value)
            return out if isinstance(out, list) else default
        except json.JSONDecodeError:
            return default
    return default


@dataclass
class PmMarket:
    condition_id: str
    question: str
    status: str                     # "open" | "closed"
    best_bid: float = 0.0           # YES token
    best_ask: float = 0.0           # YES token
    has_book: bool = False          # enableOrderBook
    volume_24h_usd: float = 0.0
    liquidity_usd: float = 0.0
    end_time: object = None

    @property
    def yes_price(self) -> float:
        return self.best_ask

    @property
    def no_cost(self) -> float:
        """Taker cost of buying NO = selling YES into the bid = 1 - best_bid."""
        return 1.0 - self.best_bid if 0.0 < self.best_bid < 1.0 else 0.0

    @property
    def has_tradable_quotes(self) -> bool:
        return self.has_book and 0.0 < self.best_bid < 1.0 and 0.0 < self.best_ask < 1.0

    @classmethod
    def from_api(cls, d: dict) -> "PmMarket":
        return cls(
            condition_id=d.get("conditionId", "") or d.get("condition_id", ""),
            question=d.get("question", "") or d.get("title", ""),
            status="closed" if d.get("closed") else ("open" if d.get("active", True) else "open"),
            best_bid=_f(d.get("bestBid")),
            best_ask=_f(d.get("bestAsk")),
            has_book=bool(d.get("enableOrderBook", False)),
            volume_24h_usd=_f(d.get("volume24hr", d.get("volumeNum"))),
            liquidity_usd=_f(d.get("liquidityNum", d.get("liquidity"))),
            end_time=parse_ts(d.get("endDate")),
        )


class PolymarketClient:
    def __init__(self, api_base: str = "https://gamma-api.polymarket.com",
                 limit: int = 500, timeout_sec: float = 20.0) -> None:
        self.base = api_base.rstrip("/")
        self.limit = limit
        self.timeout = timeout_sec

    def _get(self, path: str, params: dict | None = None) -> object:
        url = self.base + path
        if params:
            url += "?" + urllib.parse.urlencode(params)
        req = urllib.request.Request(url, headers={"User-Agent": "hanzobot-kalshi-arb/0.1"})
        deadline = 3
        while True:
            try:
                with urllib.request.urlopen(req, timeout=self.timeout) as r:
                    return json.loads(r.read().decode())
            except urllib.error.HTTPError as e:
                if e.code == 429 and deadline > 0:
                    deadline -= 1
                    time.sleep(1.0)
                    continue
                raise KalshiError(f"PM HTTP {e.code} for {url}") from e
            except (urllib.error.URLError, TimeoutError, OSError) as e:
                if deadline > 0:
                    deadline -= 1
                    time.sleep(1.0)
                    continue
                raise KalshiError(f"PM network error for {url}: {e}") from e

    def open_markets(self) -> list[PmMarket]:
        """All active order-book markets (single page; Gamma allows limit<=500)."""
        data = self._get("/markets", {"closed": "false", "active": "true",
                                      "limit": str(self.limit)})
        rows = data if isinstance(data, list) else (data or {}).get("markets") or []
        out = []
        for d in rows:
            try:
                m = PmMarket.from_api(d)
            except Exception:  # noqa: BLE001 - never let one bad row kill the scan
                continue
            if m.condition_id:
                out.append(m)
        return out

    def validate(self) -> str:
        """Live schema check: returns a one-line summary (run once before trusting)."""
        ms = [m for m in self.open_markets() if m.has_tradable_quotes][:3]
        if not ms:
            raise KalshiError("no tradable PM markets returned — check API schema/response")
        m = ms[0]
        return (f"PM API OK: {len(ms)} sample tradable, e.g. {m.condition_id[:16]}… "
                f"bid {m.best_bid} ask {m.best_ask} vol24h {m.volume_24h_usd:.0f}")


class FixturePmClient:
    """Offline stand-in fed from a saved Gamma response (tests / demos)."""

    def __init__(self, markets: list[dict]) -> None:
        self._markets = markets

    def open_markets(self) -> list[PmMarket]:
        return [PmMarket.from_api(d) for d in self._markets if d.get("conditionId")]

    def validate(self) -> str:
        return "PM fixture client (offline)"
