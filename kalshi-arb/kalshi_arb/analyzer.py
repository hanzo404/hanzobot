"""Analyze collected snapshots: how much arbitrage actually exists?

Run:  python -m kalshi_arb.analyzer [snapshots_dir]
      (or: python -m kalshi_arb.bot --analyze [snapshots_dir])
"""
from __future__ import annotations

import json
from collections import Counter
from pathlib import Path

from .collector import load_snapshots
from .config import Config
from .models import Market
from .signals import COMBO_BUY, evaluate_market, filter_markets


def analyze(snapshots_dir: str | Path, cfg: Config | None = None) -> dict:
    cfg = cfg or Config()
    snaps = load_snapshots(snapshots_dir)
    stats = {
        "snapshots": len(snaps),
        "span": [snaps[0]["ts"], snaps[-1]["ts"]] if snaps else [],
        "markets_total": 0,
        "tradable": 0,
        "opps_all": 0,
        "opps_executable": 0,
        "net_edges_cents": [],
        "gross_edges_cents": [],
        "top_tickers": Counter(),
        "per_snapshot": [],
    }
    for s in snaps:
        raw = s.get("markets", [])
        markets = [Market.from_api(d) for d in raw]
        tradable = filter_markets(markets, cfg)
        opps = []
        for m in tradable:
            o = evaluate_market(m, cfg)
            if o is not None:
                opps.append(o)
        executable = [o for o in opps if o.kind == COMBO_BUY]
        stats["markets_total"] += len(markets)
        stats["tradable"] += len(tradable)
        stats["opps_all"] += len(opps)
        stats["opps_executable"] += len(executable)
        for o in opps:
            stats["gross_edges_cents"].append(round(o.gross_edge * 100, 2))
            stats["top_tickers"][o.market.ticker] += 1
        for o in executable:
            stats["net_edges_cents"].append(round(o.net_edge * 100, 2))
        stats["per_snapshot"].append(
            {"ts": s.get("ts"), "markets": len(markets), "tradable": len(tradable),
             "opps": len(opps), "executable": len(executable)}
        )

    net = sorted(stats["net_edges_cents"])
    gross = sorted(stats["gross_edges_cents"])

    def _pct(xs, q):
        return xs[int(q * (len(xs) - 1))] if xs else 0.0

    stats["net_edge_cents_stats"] = {
        "n": len(net), "min": _pct(net, 0), "p50": _pct(net, 0.5),
        "p90": _pct(net, 0.9), "max": _pct(net, 1),
    } if net else {}
    stats["gross_edge_cents_stats"] = {
        "n": len(gross), "min": _pct(gross, 0), "p50": _pct(gross, 0.5),
        "max": _pct(gross, 1),
    } if gross else {}
    stats["top_tickers"] = dict(stats["top_tickers"].most_common(10))
    return stats


def format_report(stats: dict) -> str:
    L = []
    L.append(f"snapshots: {stats['snapshots']}  (span {stats['span'][0]} → {stats['span'][1]})"
             if stats["snapshots"] else "snapshots: 0 — nothing collected yet")
    L.append(f"markets scanned : {stats['markets_total']}")
    L.append(f"tradable (filters): {stats['tradable']}")
    L.append(f"opportunities   : {stats['opps_all']} total, {stats['opps_executable']} executable (COMBO_BUY)")
    ne = stats.get("net_edge_cents_stats")
    if ne:
        L.append(f"net edge (c)    : min {ne['min']} | p50 {ne['p50']} | p90 {ne['p90']} | max {ne['max']}")
    ge = stats.get("gross_edge_cents_stats")
    if ge:
        L.append(f"gross edge (c)  : min {ge['min']} | p50 {ge['p50']} | max {ge['max']}")
    if stats["top_tickers"]:
        L.append("top tickers     : " + ", ".join(f"{k}×{v}" for k, v in stats["top_tickers"].items()))
    if stats["per_snapshot"]:
        L.append("per-snapshot    : " + " | ".join(
            f"{x['ts'][:16]} m:{x['markets']} t:{x['tradable']} o:{x['opps']}" for x in stats["per_snapshot"][-8:]))
    return "\n".join(L)


def main(argv: list[str] | None = None) -> int:
    import argparse
    ap = argparse.ArgumentParser(description="Analyze Kalshi arb snapshots")
    ap.add_argument("snapshots_dir", nargs="?", default="data/snapshots")
    ap.add_argument("--config", default=None)
    ap.add_argument("--json", action="store_true", help="print raw JSON instead of a report")
    args = ap.parse_args(argv)
    stats = analyze(args.snapshots_dir, Config.load(args.config))
    print(json.dumps(stats, indent=2) if args.json else format_report(stats))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
