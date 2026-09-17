"""Snapshot collection: persist raw API scans for later backtesting.

Every snapshot is one full open-markets scan (raw API objects) plus the
opportunities detected on it. Snapshots are the ground truth from which
the analyzer estimates how much arbitrage actually exists.

Layout: <snapshots_dir>/YYYYMMDDTHHMMSSZ.json
"""
from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path

SCHEMA = 1


def snapshot_filename(ts: datetime | None = None) -> str:
    ts = ts or datetime.now(timezone.utc)
    return ts.strftime("%Y%m%dT%H%M%SZ") + ".json"


def save_snapshot(
    snapshots_dir: str | Path,
    raw_markets: list[dict],
    opportunities: list[dict],
    source: str = "kalshi-v2",
    endpoint: str = "/markets?status=open",
    ts: datetime | None = None,
) -> Path:
    ts = ts or datetime.now(timezone.utc)
    d = Path(snapshots_dir)
    d.mkdir(parents=True, exist_ok=True)
    path = d / snapshot_filename(ts)
    doc = {
        "schema": SCHEMA,
        "ts": ts.astimezone(timezone.utc).isoformat().replace("+00:00", "Z"),
        "source": source,
        "endpoint": endpoint,
        "markets": raw_markets,
        "opportunities": opportunities,
    }
    path.write_text(json.dumps(doc, separators=(",", ":")) + "\n")
    return path


def load_snapshots(snapshots_dir: str | Path) -> list[dict]:
    d = Path(snapshots_dir)
    if not d.exists():
        return []
    out = []
    for p in sorted(d.glob("*.json")):
        try:
            out.append(json.loads(p.read_text()))
        except json.JSONDecodeError:
            continue
    return out


def prune(snapshots_dir: str | Path, keep: int) -> int:
    """Keep only the newest `keep` snapshots. Returns number removed."""
    d = Path(snapshots_dir)
    if not d.exists() or keep <= 0:
        return 0
    files = sorted(d.glob("*.json"))
    excess = len(files) - keep
    removed = 0
    for p in files[:max(0, excess)]:
        p.unlink()
        removed += 1
    return removed
