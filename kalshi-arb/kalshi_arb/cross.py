"""Cross-venue arbitrage: Kalshi <-> Polymarket.

A pair is an EXPLICIT human-approved mapping between one Kalshi binary
market and one Polymarket market that resolve the SAME real-world event
(same resolution source). Explicit mapping is deliberate: a wrong
cross-venue match is not a small loss, it is a structurally broken trade.

Two guaranteed-payout combos per pair (exactly one leg pays $1):

  XV_A: buy YES on Kalshi (k_yes_ask)  + buy NO on Polymarket (1 - pm_best_bid)
  XV_B: buy NO  on Kalshi (k_no_ask)   + buy YES on Polymarket (pm_best_ask)

Fees: Kalshi taker (0.07 * P * (1-P)) + Polymarket taker
(pair-level rate from the venue map, default 0.05).
"""
from __future__ import annotations

import json
import re
from dataclasses import dataclass
from pathlib import Path

from .config import Config
from .models import Market, kalshi_fee
from .pm_client import PmMarket

XV_A = "XV_A"   # YES@Kalshi + NO@Polymarket
XV_B = "XV_B"   # NO@Kalshi  + YES@Polymarket


@dataclass
class Pair:
    label: str
    kalshi_ticker: str
    pm_condition_id: str
    pm_fee_rate: float = 0.05


def load_pairs(path: str | Path, default_pm_rate: float = 0.05) -> list[Pair]:
    p = Path(path)
    if not p.exists():
        return []
    data = json.loads(p.read_text())
    out = []
    for d in data.get("pairs", []):
        kt = d.get("kalshi_ticker", "")
        pc = d.get("pm_condition_id", "")
        if kt and pc:
            out.append(Pair(
                label=d.get("label", kt),
                kalshi_ticker=kt,
                pm_condition_id=pc,
                pm_fee_rate=float(d.get("pm_fee_rate", default_pm_rate)),
            ))
    return out


def pm_fee(price: float, qty: float, rate: float) -> float:
    """Polymarket taker fee: C * rate * p * (1-p) (makers pay zero)."""
    if qty <= 0 or price <= 0.0 or price >= 1.0:
        return 0.0
    return qty * rate * price * (1.0 - price)


@dataclass
class CrossOpportunity:
    kind: str                       # XV_A | XV_B
    pair: Pair
    kalshi: Market
    pm: PmMarket
    k_price: float                  # price paid on the Kalshi leg
    p_price: float                  # price paid on the Polymarket leg
    gross_edge: float
    fees: float
    net_edge: float
    max_pairs: float                # limited by the Kalshi ask size

    @property
    def cost_per_pair(self) -> float:
        return self.k_price + self.p_price + self.fees


def pm_side_ok(pm: PmMarket, cfg: Config) -> bool:
    return (pm.has_tradable_quotes
            and pm.liquidity_usd >= cfg.pm_min_liquidity_usd
            and pm.volume_24h_usd >= cfg.pm_min_volume_24h_usd)


def evaluate_pair(pair: Pair, k: Market, p: PmMarket, cfg: Config) -> CrossOpportunity | None:
    """Best cross-venue combo for one mapped pair, or None."""
    if not k.has_tradable_quotes or not pm_side_ok(p, cfg):
        return None
    best: CrossOpportunity | None = None

    def consider(kind: str, k_price: float, p_price: float, k_size: float) -> None:
        nonlocal best
        if k_price <= 0 or p_price <= 0 or k_price >= 1 or p_price >= 1:
            return
        gross = 1.0 - (k_price + p_price)
        fees = kalshi_fee(k_price, 1, cfg.fee_rate) + pm_fee(p_price, 1, pair.pm_fee_rate)
        net = gross - fees
        if net >= cfg.xv_min_net_edge:
            opp = CrossOpportunity(kind, pair, k, p, k_price, p_price, gross, fees,
                                   net, max(1.0, k_size))
            if best is None or opp.net_edge > best.net_edge:
                best = opp

    # XV_A: YES@K (k_yes_ask) + NO@PM (1 - pm_best_bid), size from K's YES ask
    consider(XV_A, k.yes_ask, p.no_cost, k.yes_ask_size)
    # XV_B: NO@K (k_no_ask) + YES@PM (pm_best_ask), size from K's NO ask
    consider(XV_B, k.no_ask, p.best_ask, k.no_ask_size)
    return best


# --------------------------------------------------------------- matching

_STOP = {
    "the", "a", "an", "will", "is", "are", "of", "on", "in", "to", "for",
    "by", "at", "vs", "versus", "over", "under", "above", "below", "will",
}


def _tokens(text: str) -> set:
    t = re.sub(r"[^a-z0-9 ]+", " ", text.lower())
    return {w for w in t.split() if w and w not in _STOP}


def suggest_matches(kalshi_markets: list[Market], pm_markets: list[PmMarket],
                    top_n: int = 5) -> list[dict]:
    """Fuzzy candidate pairs for HUMAN review (never auto-executed).

    Scores Kalshi x Polymarket on title-token overlap plus end-date
    proximity. Output is a suggestion list; the user copies accepted rows
    into the venue map.
    """
    from datetime import datetime, timezone
    out = []
    for k in kalshi_markets:
        if not k.is_binary or k.is_multivariate:
            continue
        kt = _tokens(k.title)
        if not kt:
            continue
        for p in pm_markets:
            pt = _tokens(p.question)
            if not pt:
                continue
            overlap = len(kt & pt) / min(len(kt), len(pt))
            date_bonus = 0.0
            if k.close_time and p.end_time:
                try:
                    dt = abs((k.close_time - p.end_time).total_seconds()) / 86400.0
                    if dt <= 1.0:
                        date_bonus = 0.25
                except TypeError:  # naive vs aware
                    pass
            score = overlap + date_bonus
            if score >= 0.4:
                out.append({"score": round(score, 3),
                            "kalshi_ticker": k.ticker,
                            "kalshi_title": k.title[:80],
                            "pm_condition_id": p.condition_id,
                            "pm_question": p.question[:80]})
    out.sort(key=lambda d: d["score"], reverse=True)
    return out[:top_n * 3]
