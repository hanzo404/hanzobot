"""Risk management: sizing caps + the daily-loss kill switch.

Rules learned from the field (poly-maker, KalshiMarketMaker):
  * hard per-opportunity stake cap
  * hard max number of open positions
  * global daily realized-loss limit -> kill switch stops all trading
  * every decision is logged with its reason (auditability)
"""
from __future__ import annotations

from dataclasses import dataclass

from .config import Config
from .portfolio import PaperPortfolio
from .signals import Opportunity


@dataclass
class RiskDecision:
    allowed: bool
    reason: str


def stake_for(opp: Opportunity, cfg: Config, bankroll: float) -> float:
    """Stake size in USD for one opportunity (both legs combined)."""
    stake = min(cfg.max_stake_usd, bankroll * cfg.stake_pct_of_bankroll)
    # never exceed what the visible book can take
    stake = min(stake, opp.max_pairs * opp.cost_per_pair)
    return round(stake, 2)


class RiskManager:
    def __init__(self, cfg: Config, portfolio: PaperPortfolio) -> None:
        self.cfg = cfg
        self.pf = portfolio

    @property
    def halted(self) -> bool:
        return self.pf.halted

    def check(self, opp: Opportunity, stake: float) -> RiskDecision:
        if self.halted:
            return RiskDecision(False, "kill switch active (daily loss limit hit)")
        if len(self.pf.positions) >= self.cfg.max_open_positions:
            return RiskDecision(False, f"max open positions reached ({self.cfg.max_open_positions})")
        if stake <= 0:
            return RiskDecision(False, "stake rounds to zero")
        return RiskDecision(True, "ok")
