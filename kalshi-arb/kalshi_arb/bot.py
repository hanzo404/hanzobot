"""Main loop: scan → filter → detect → risk → paper-execute → settle → report.

Usage:
  python -m kalshi_arb.bot --once                 # single scan, live API
  python -m kalshi_arb.bot --loop --interval 15   # continuous
  python -m kalshi_arb.bot --fixture f.json       # offline demo/test data
  python -m kalshi_arb.bot --report               # portfolio status
  python -m kalshi_arb.bot --reset                # reset paper portfolio
"""
from __future__ import annotations

import argparse
import json
import logging
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

from .analyzer import analyze, format_report
from .client import FixtureClient, KalshiClient, KalshiError
from .collector import prune, save_snapshot
from .config import Config
from .models import Market
from .notifier import Notifier
from .portfolio import PaperPortfolio
from .risk import RiskManager, stake_for
from .signals import COMBO_BUY, scan

log = logging.getLogger("kalshi_arb")


def _setup_logging(verbose: bool) -> None:
    logging.basicConfig(
        level=logging.DEBUG if verbose else logging.INFO,
        format="%(asctime)s %(levelname)-7s %(name)s | %(message)s",
        datefmt="%H:%M:%S",
    )


def _make_client(args, cfg: Config) -> object:
    if args.fixture:
        data = json.loads(Path(args.fixture).read_text())
        return FixtureClient(data.get("markets", []))
    return KalshiClient(cfg)


def _load_or_init_portfolio(cfg: Config) -> PaperPortfolio:
    path = Path(cfg.state_file)
    if path.exists():
        return PaperPortfolio.load(path)
    pf = PaperPortfolio(bankroll_usd=cfg.bankroll_usd, cash_usd=cfg.bankroll_usd)
    pf.save(path)
    return pf


def run_scan(client, pf: PaperPortfolio, cfg: Config, notifier: Notifier,
             now: datetime | None = None) -> dict:
    """One scan cycle. Returns a summary dict (for tests / --once output)."""
    pf._roll_day()
    if pf.apply_kill_switch(cfg.daily_max_loss_usd):
        notifier.kill_switch(pf.halted_reason)
        pf.save(cfg.state_file)
        return {"halted": True, "reason": pf.halted_reason}

    raw = client.open_markets_raw()
    markets = [Market.from_api(d) for d in raw]
    opps = scan(markets, cfg, now)

    if cfg.collect:
        opp_docs = [
            {"ticker": o.market.ticker, "kind": o.kind,
             "yes": o.yes_price, "no": o.no_price,
             "gross_c": round(o.gross_edge * 100, 2), "fees_c": round(o.fees * 100, 2),
             "net_c": round(o.net_edge * 100, 2)}
            for o in opps
        ]
        source = "kalshi-v2" if isinstance(client, KalshiClient) else "fixture"
        save_snapshot(cfg.snapshots_dir, raw, opp_docs, source=source)
        prune(cfg.snapshots_dir, cfg.snapshot_keep)
    risk = RiskManager(cfg, pf)
    summary = {
        "scanned": len(markets),
        "opportunities": len(opps),
        "new_trades": 0,
        "traded": [],
        "top": [],
        "halted": pf.halted,
    }

    for opp in opps[:5]:  # report the top 5 edges
        summary["top"].append({
            "ticker": opp.market.ticker,
            "kind": opp.kind,
            "net_edge_c": round(opp.net_edge * 100, 2),
            "title": opp.market.title[:80],
        })

    if not pf.halted:
        for opp in opps:
            if opp.kind != COMBO_BUY:
                continue  # paper mode executes only the lock-in shape
            if any(p.market_ticker == opp.market.ticker for p in pf.open_positions):
                continue  # one position per market (persisted, survives restarts)
            if not notifier.opportunity(opp):
                continue  # already seen this exact quote combo
            stake = stake_for(opp, cfg, pf.bankroll_now())
            decision = risk.check(opp, stake)
            if not decision.allowed:
                log.info("skip %s: %s", opp.market.ticker, decision.reason)
                continue
            pos = pf.open_combo_buy(opp, stake)
            if pos is None:
                continue
            pf.apply_kill_switch(cfg.daily_max_loss_usd)
            notifier.trade(pos, stake)
            summary["new_trades"] += 1
            summary["traded"].append(pos.market_ticker)
            if len(pf.positions) >= cfg.max_open_positions:
                break

    # settle anything that closed
    for pos in list(pf.open_positions):
        m = client.market(pos.market_ticker)
        if m is None:
            continue
        settled = pf.settle_if_closed(m)
        if settled is not None:
            log.info(
                "SETTLED %s result=%s realized=%+.4f USD",
                pos.market_ticker, settled.result, settled.realized_usd,
            )
            if pf.apply_kill_switch(cfg.daily_max_loss_usd):
                notifier.kill_switch(pf.halted_reason)

    pf.save(cfg.state_file)
    summary["bankroll"] = pf.bankroll_now()
    summary["cash"] = pf.cash_usd
    summary["open"] = len(pf.open_positions)
    summary["realized_total"] = pf.realized_pnl_usd
    summary["realized_day"] = pf.realized_day_usd
    return summary


def _print_summary(s: dict) -> None:
    if s.get("halted"):
        log.warning("HALTED: %s", s.get("reason", "kill switch"))
        return
    line = (
        f"scan: {s['scanned']} markets | {s['opportunities']} opps | "
        f"+{s['new_trades']} trades | bankroll ${s['bankroll']:.2f} "
        f"(cash ${s['cash']:.2f}, {s['open']} open) | "
        f"realized total {s['realized_total']:+.4f} / day {s['realized_day']:+.4f}"
    )
    log.info(line)
    for t in s["top"]:
        log.info("  edge %s: %+0.2fc  %s  [%s]",
                 t["ticker"], t["net_edge_c"], t["kind"], t["title"])


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="Kalshi prediction-market arbitrage bot (paper)")
    ap.add_argument("--config", default=None, help="path to config JSON")
    ap.add_argument("--once", action="store_true", help="single scan then exit")
    ap.add_argument("--loop", action="store_true", help="run continuously")
    ap.add_argument("--interval", type=float, default=15.0, help="seconds between scans")
    ap.add_argument("--fixture", default=None, help="offline: JSON file with an API response")
    ap.add_argument("--analyze", nargs="?", const="data/snapshots", default=None,
                    metavar="DIR", help="analyze collected snapshots and exit")
    ap.add_argument("--collect", action="store_true", help="save a snapshot on every scan")
    ap.add_argument("--report", action="store_true", help="print portfolio status and exit")
    ap.add_argument("--reset", action="store_true", help="reset the paper portfolio")
    ap.add_argument("-v", "--verbose", action="store_true")
    args = ap.parse_args(argv)
    _setup_logging(args.verbose)

    cfg = Config.load(args.config)
    if args.collect:
        cfg.collect = True
    if args.analyze is not None:
        print(format_report(analyze(args.analyze, cfg)))
        return 0
    if args.report or args.reset:
        pf = _load_or_init_portfolio(cfg)
        if args.reset:
            pf.reset(cfg.bankroll_usd)
            pf.save(cfg.state_file)
            print(f"paper portfolio reset to ${cfg.bankroll_usd:.2f}")
            return 0
        print(
            f"bankroll ${pf.bankroll_now():.2f} | cash ${pf.cash_usd:.2f} | "
            f"open {len(pf.open_positions)} | realized total {pf.realized_pnl_usd:+.4f} | "
            f"realized day {pf.realized_day_usd:+.4f} | halted {pf.halted}"
        )
        return 0

    client = _make_client(args, cfg)
    pf = _load_or_init_portfolio(cfg)
    notifier = Notifier(cfg.telegram_token, cfg.telegram_chat_id)
    log.info("starting | bankroll $%.2f | state=%s%s",
             pf.bankroll_now(), cfg.state_file,
             f" | fixture={args.fixture}" if args.fixture else "")

    if args.once or not args.loop:
        try:
            s = run_scan(client, pf, cfg, notifier)
        except KalshiError as e:
            log.error("scan failed: %s", e)
            return 1
        _print_summary(s)
        return 0

    while True:
        try:
            s = run_scan(client, pf, cfg, notifier)
            _print_summary(s)
        except KalshiError as e:
            log.warning("scan failed (retrying): %s", e)
        except Exception:  # noqa: BLE001 - the loop must survive bugs
            log.exception("unexpected error in scan loop")
        time.sleep(max(2.0, args.interval))


if __name__ == "__main__":
    sys.exit(main())
