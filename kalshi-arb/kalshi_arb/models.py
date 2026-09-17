"""Domain models: markets, quotes, and the Kalshi fee model."""
from __future__ import annotations

import math
from dataclasses import dataclass
from datetime import datetime, timezone


def _f(value: str | None, default: float = 0.0) -> float:
    """Parse API numeric strings safely (they arrive as strings, sometimes '')."""
    if value in (None, ""):
        return default
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def parse_ts(value: str | None) -> datetime | None:
    """Parse ISO-8601 timestamps like 2026-09-21T14:00:00Z."""
    if not value:
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None


def kalshi_fee(price: float, qty: float, fee_rate: float = 0.07) -> float:
    """Approximate Kalshi taker fee for a market order.

    Kalshi charges (per docs, v1-era formula): 0.07 * C * P * (1-P),
    rounded UP to the nearest cent. We keep the coefficient configurable
    because fee schedules change — verify against current docs before
    going live.
    """
    if qty <= 0 or price <= 0.0 or price >= 1.0:
        return 0.0
    return math.ceil(fee_rate * qty * price * (1.0 - price) * 100.0) / 100.0


@dataclass
class Market:
    ticker: str
    title: str
    event_ticker: str
    status: str                       # "active" | "open" | "closed"
    market_type: str                  # "binary" | ...
    result: str                       # "yes" | "no" | "" once closed
    close_time: datetime | None
    mve_collection_ticker: str        # non-empty => multivariate (excluded)
    yes_bid: float = 0.0
    yes_ask: float = 0.0
    yes_ask_size: float = 0.0
    yes_bid_size: float = 0.0
    no_bid: float = 0.0
    no_ask: float = 0.0
    no_ask_size: float = 0.0
    no_bid_size: float = 0.0
    liquidity_usd: float = 0.0
    volume_24h_usd: float = 0.0

    @property
    def is_binary(self) -> bool:
        return self.market_type == "binary"

    @property
    def is_multivariate(self) -> bool:
        return bool(self.mve_collection_ticker)

    @property
    def has_tradable_quotes(self) -> bool:
        """Both legs must have a real ask strictly inside (0, 1)."""
        return 0.0 < self.yes_ask < 1.0 and 0.0 < self.no_ask < 1.0

    @classmethod
    def from_api(cls, d: dict) -> "Market":
        return cls(
            ticker=d.get("ticker", ""),
            title=d.get("title", ""),
            event_ticker=d.get("event_ticker", ""),
            status=d.get("status", ""),
            market_type=d.get("market_type", ""),
            result=d.get("result", "") or "",
            close_time=parse_ts(d.get("close_time") or d.get("expiration_time")),
            mve_collection_ticker=d.get("mve_collection_ticker", "") or "",
            yes_bid=_f(d.get("yes_bid_dollars")),
            yes_ask=_f(d.get("yes_ask_dollars")),
            yes_ask_size=_f(d.get("yes_ask_size_fp")),
            yes_bid_size=_f(d.get("yes_bid_size_fp")),
            no_bid=_f(d.get("no_bid_dollars")),
            no_ask=_f(d.get("no_ask_dollars")),
            no_ask_size=_f(d.get("no_ask_size_fp")),
            no_bid_size=_f(d.get("no_bid_size_fp")),
            liquidity_usd=_f(d.get("liquidity_dollars")),
            volume_24h_usd=_f(d.get("volume_24h_fp")),
        )
