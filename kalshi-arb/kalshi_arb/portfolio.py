"""Paper-trading portfolio with JSON persistence.

A COMBO_BUY position locks its P&L at open time: one of the two legs
pays exactly $1.00 per pair at settlement, so realized profit =
pairs * $1.00 - total cost (fees included). Mark-to-market is
therefore constant — we still track status so settlement is recorded
when the market actually closes.
"""
from __future__ import annotations

import json
from dataclasses import dataclass, field
from datetime import date, datetime, timezone
from pathlib import Path

from .signals import Opportunity

COMBO_BUY = "COMBO_BUY"


@dataclass
class Position:
    id: str
    market_ticker: str
    event_ticker: str
    title: str
    kind: str                  # COMBO_BUY | MAKER_COMBO | XV_A | XV_B
    pairs: float
    cost_usd: float            # total cost of both legs + fees
    edge_usd: float            # locked-in edge = 1.00*pairs - cost_usd
    opened_at: str             # iso8601 utc
    status: str = "open"       # open | settled
    settled_at: str | None = None
    result: str = ""           # yes/no at settlement
    realized_usd: float = 0.0
    meta: dict = field(default_factory=dict)   # e.g. pm_condition_id for cross


@dataclass
class PaperPortfolio:
    bankroll_usd: float
    cash_usd: float
    realized_pnl_usd: float = 0.0
    realized_day_usd: float = 0.0
    day: str = field(default_factory=lambda: date.today().isoformat())
    positions: list[Position] = field(default_factory=list)
    halted: bool = False
    trades: int = 0
    halted_reason: str = ""
    extra: dict = field(default_factory=dict)   # strategy state (e.g. maker warm counters)

    # -------------------------------------------------------------- helpers

    def _roll_day(self) -> None:
        today = date.today().isoformat()
        if today != self.day:
            self.day = today
            self.realized_day_usd = 0.0
            # auto-resume the kill switch on a new day
            self.halted = False
            self.halted_reason = ""

    @property
    def open_positions(self) -> list[Position]:
        return [p for p in self.positions if p.status == "open"]

    # ------------------------------------------------------------ operations

    def open_combo_buy(self, opp: Opportunity, stake_usd: float) -> Position | None:
        return self.open_position(
            kind=opp.kind,
            market_ticker=opp.market.ticker,
            event_ticker=opp.market.event_ticker,
            title=opp.market.title,
            cost_per_pair=opp.cost_per_pair,
            stake_usd=stake_usd,
        )

    def open_position(self, kind: str, market_ticker: str, event_ticker: str,
                      title: str, cost_per_pair: float, stake_usd: float,
                      meta: dict | None = None) -> Position | None:
        """Open a guaranteed-$1-per-pair position (combo or cross-venue)."""
        if cost_per_pair <= 0 or cost_per_pair >= 1.0:
            return None
        pairs = int(stake_usd // cost_per_pair)
        if pairs < 1:
            return None
        cost = round(pairs * cost_per_pair, 4)
        if cost > self.cash_usd:
            pairs = int(self.cash_usd // cost_per_pair)
            if pairs < 1:
                return None
            cost = round(pairs * cost_per_pair, 4)
        edge = round(pairs * 1.00 - cost, 4)
        pos = Position(
            id=f"{market_ticker}@{datetime.now(timezone.utc).timestamp()}",
            market_ticker=market_ticker,
            event_ticker=event_ticker,
            title=title[:160],
            kind=kind,
            pairs=float(pairs),
            cost_usd=cost,
            edge_usd=edge,
            opened_at=datetime.now(timezone.utc).isoformat(),
            meta=meta or {},
        )
        self.cash_usd = round(self.cash_usd - cost, 4)
        self.positions.append(pos)
        self.trades += 1
        return pos

    def settle_if_closed(self, market) -> Position | None:
        """Settle a still-open position if its market closed with a result.

        The caller is responsible for invoking apply_kill_switch() with
        the configured limit afterwards (the portfolio itself is
        config-free).
        """
        if market.status != "closed" or market.result not in ("yes", "no"):
            return None
        self._roll_day()
        for p in self.positions:
            if p.market_ticker != market.ticker or p.status != "open":
                continue
            payout = p.pairs * 1.00
            p.realized_usd = round(payout - p.cost_usd, 4)
            p.status = "settled"
            p.settled_at = datetime.now(timezone.utc).isoformat()
            p.result = market.result
            self.cash_usd = round(self.cash_usd + payout, 4)
            self.realized_pnl_usd = round(self.realized_pnl_usd + p.realized_usd, 4)
            self.realized_day_usd = round(self.realized_day_usd + p.realized_usd, 4)
            return p
        return None

    def apply_kill_switch(self, daily_max_loss_usd: float) -> bool:
        """Trip the kill switch if today's realized loss exceeds the limit."""
        if not self.halted and self.realized_day_usd <= -abs(daily_max_loss_usd):
            self.halted = True
            self.halted_reason = (
                f"daily realized loss {self.realized_day_usd:.2f} USD hit the "
                f"{daily_max_loss_usd:.2f} USD limit"
            )
            return True
        return False

    def bankroll_now(self) -> float:
        return round(self.cash_usd + sum(p.edge_usd for p in self.open_positions), 4)

    # ---------------------------------------------------------- persistence

    def save(self, path: str | Path) -> None:
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        data = {
            "bankroll_usd": self.bankroll_usd,
            "cash_usd": self.cash_usd,
            "realized_pnl_usd": self.realized_pnl_usd,
            "realized_day_usd": self.realized_day_usd,
            "day": self.day,
            "positions": [vars(p) for p in self.positions],
            "halted": self.halted,
            "halted_reason": self.halted_reason,
            "trades": self.trades,
            "extra": self.extra,
        }
        Path(path).write_text(json.dumps(data, indent=2) + "\n")

    @classmethod
    def load(cls, path: str | Path) -> "PaperPortfolio":
        p = Path(path)
        if not p.exists():
            raise FileNotFoundError(path)
        d = json.loads(p.read_text())
        pf = cls(
            bankroll_usd=d["bankroll_usd"],
            cash_usd=d["cash_usd"],
            realized_pnl_usd=d.get("realized_pnl_usd", 0.0),
            realized_day_usd=d.get("realized_day_usd", 0.0),
            day=d.get("day", date.today().isoformat()),
            halted=d.get("halted", False),
            halted_reason=d.get("halted_reason", ""),
            trades=d.get("trades", 0),
            extra=d.get("extra", {}),
        )
        pf.positions = [Position(**x) for x in d.get("positions", [])]
        return pf

    def reset(self, bankroll_usd: float) -> None:
        self.bankroll_usd = bankroll_usd
        self.cash_usd = bankroll_usd
        self.realized_pnl_usd = 0.0
        self.realized_day_usd = 0.0
        self.day = date.today().isoformat()
        self.positions = []
        self.halted = False
        self.halted_reason = ""
        self.trades = 0
