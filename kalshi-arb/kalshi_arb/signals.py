"""Opportunity detection.

Two structural shapes on a single binary market:

  COMBO_BUY  : yes_ask + no_ask < 1.00  -> buy YES at ask and NO at ask.
               At settlement exactly one leg pays $1.00, so the pair
               locks in $1.00 - (yes_ask + no_ask) - fees.
  COMBO_SELL : yes_bid + no_bid > 1.00  -> the mirror image (sell both
               sides). Kept as a *signal only*: shorting prediction
               markets needs a live account and margin, and paper mode
               does not model that.
"""
from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone

from .config import Config
from .models import Market, kalshi_fee

COMBO_BUY = "COMBO_BUY"
COMBO_SELL = "COMBO_SELL"


@dataclass
class Opportunity:
    kind: str
    market: Market
    yes_price: float          # price paid for the YES leg
    no_price: float           # price paid for the NO leg
    gross_edge: float         # 1.00 - (yes + no)  (or the bid-side mirror)
    fees: float               # sum of both legs' fees (per pair, qty=1)
    net_edge: float           # gross - fees
    max_pairs: float          # limited by visible ask sizes

    @property
    def cost_per_pair(self) -> float:
        return self.yes_price + self.no_price + self.fees


def filter_markets(markets: list[Market], cfg: Config, now: datetime | None = None) -> list[Market]:
    """Apply liquidity/volume/type/expiry filters before evaluating edges."""
    now = now or datetime.now(timezone.utc)
    ok = []
    for m in markets:
        if not m.is_binary or m.is_multivariate:
            continue
        if m.status not in ("active", "open"):
            continue
        if m.liquidity_usd < cfg.min_liquidity_usd:
            continue
        if m.volume_24h_usd < cfg.min_volume_24h_usd:
            continue
        if m.close_time is not None:
            hours_left = (m.close_time - now).total_seconds() / 3600.0
            if hours_left < cfg.min_hours_to_expiry:
                continue
        ok.append(m)
    return ok


def evaluate_market(m: Market, cfg: Config) -> Opportunity | None:
    """Return the best opportunity on this market, or None."""
    if not m.has_tradable_quotes:
        return None

    best: Opportunity | None = None

    # --- COMBO_BUY (the executable one in paper mode) ---
    gross = 1.00 - (m.yes_ask + m.no_ask)
    fees = kalshi_fee(m.yes_ask, 1, cfg.fee_rate) + kalshi_fee(m.no_ask, 1, cfg.fee_rate)
    max_pairs = min(m.yes_ask_size, m.no_ask_size)
    if max_pairs >= 1 and (m.yes_ask_size >= cfg.min_quote_size or m.no_ask_size >= cfg.min_quote_size):
        net = gross - fees
        if net >= cfg.min_net_edge:
            best = Opportunity(COMBO_BUY, m, m.yes_ask, m.no_ask, gross, fees, net, max_pairs)

    # --- COMBO_SELL (signal only) ---
    gross_s = (m.yes_bid + m.no_bid) - 1.00
    if gross_s >= cfg.min_net_edge:
        opp = Opportunity(
            COMBO_SELL, m, m.yes_bid, m.no_bid, gross_s, 0.0, gross_s,
            min(m.yes_ask_size, m.no_ask_size),
        )
        if best is None or opp.gross_edge > best.gross_edge:
            best = opp
    return best


def scan(markets: list[Market], cfg: Config, now: datetime | None = None) -> list[Opportunity]:
    """Filter + evaluate, sorted by net edge descending."""
    opps = []
    for m in filter_markets(markets, cfg, now):
        o = evaluate_market(m, cfg)
        if o is not None:
            opps.append(o)
    return sorted(opps, key=lambda o: o.net_edge, reverse=True)
