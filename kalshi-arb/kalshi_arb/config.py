"""Configuration for the Kalshi arbitrage bot.

All values are tunable via a JSON config file (see config.example.json)
and/or environment variables (KA_ prefix).
"""
from __future__ import annotations

import json
import os
from dataclasses import dataclass, fields
from pathlib import Path


@dataclass
class Config:
    # --- API ---
    api_base: str = "https://api.elections.kalshi.com/trade-api/v2"
    request_timeout_sec: float = 20.0
    scan_limit: int = 200          # markets per page
    scan_pages: int = 5            # max pages per scan (limit*pages total)

    # --- Market filters ---
    min_liquidity_usd: float = 2000.0
    min_volume_24h_usd: float = 500.0
    min_hours_to_expiry: float = 1.0   # skip markets about to expire
    min_quote_size: float = 10.0       # contracts visible at the ask

    # --- Opportunity / sizing ---
    min_net_edge: float = 0.005        # min net edge per pair (USD), after fees
    fee_rate: float = 0.07             # Kalshi taker fee coefficient: fee = rate*C*P*(1-P)
    max_stake_usd: float = 25.0        # max stake per opportunity
    stake_pct_of_bankroll: float = 0.05

    # --- Risk ---
    max_open_positions: int = 5
    daily_max_loss_usd: float = 50.0   # realized daily loss that triggers the kill switch

    # --- Paper portfolio ---
    bankroll_usd: float = 1000.0
    state_file: str = "state/paper_state.json"

    # --- Notifications (optional) ---
    telegram_token: str = ""
    telegram_chat_id: str = ""

    def __post_init__(self) -> None:
        # Environment overrides (KA_MIN_NET_EDGE etc.)
        for f in fields(self):
            env_key = "KA_" + f.name.upper()
            if env_key in os.environ:
                raw = os.environ[env_key].strip()
                cur = getattr(self, f.name)
                if isinstance(cur, bool):
                    val: object = raw.lower() in ("1", "true", "yes")
                elif isinstance(cur, int):
                    val = int(raw)
                elif isinstance(cur, float):
                    val = float(raw)
                else:
                    val = raw
                setattr(self, f.name, val)

    @classmethod
    def load(cls, path: str | os.PathLike | None = None) -> "Config":
        cfg = cls()
        if path:
            p = Path(path)
            if p.exists():
                data = json.loads(p.read_text())
                known = {f.name for f in fields(cls)}
                for k, v in data.items():
                    if k in known:
                        setattr(cfg, k, v)
        return cfg

    def save_default(self, path: str | os.PathLike) -> None:
        data = {f.name: getattr(self, f.name) for f in fields(self)}
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        Path(path).write_text(json.dumps(data, indent=2) + "\n")
