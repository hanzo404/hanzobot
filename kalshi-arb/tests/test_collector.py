"""Tests for snapshot collection and analysis."""
import json
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path

from kalshi_arb.analyzer import analyze, format_report
from kalshi_arb.collector import load_snapshots, prune, save_snapshot
from kalshi_arb.config import Config
from kalshi_arb.models import Market

FIXTURE = Path(__file__).parent / "fixtures" / "markets_sample.json"


def raw_markets():
    return json.loads(FIXTURE.read_text())["markets"]


class CollectorTest(unittest.TestCase):
    def test_save_and_load(self):
        with tempfile.TemporaryDirectory() as td:
            ts = datetime(2026, 9, 17, 14, 0, 0, tzinfo=timezone.utc)
            path = save_snapshot(td, raw_markets(),
                                 [{"ticker": "KXETHABOVE-26SEP18-4000", "kind": "COMBO_BUY",
                                   "yes": 0.45, "no": 0.46, "gross_c": 9.0,
                                   "fees_c": 4.0, "net_c": 5.0}],
                                 ts=ts)
            self.assertTrue(path.exists())
            doc = json.loads(path.read_text())
            self.assertEqual(doc["schema"], 1)
            self.assertEqual(doc["source"], "kalshi-v2")
            self.assertEqual(len(doc["markets"]), 4)
            snaps = load_snapshots(td)
            self.assertEqual(len(snaps), 1)
            self.assertEqual(snaps[0]["opportunities"][0]["net_c"], 5.0)

    def test_prune_keeps_newest(self):
        with tempfile.TemporaryDirectory() as td:
            base = datetime(2026, 9, 17, 10, 0, 0, tzinfo=timezone.utc)
            from datetime import timedelta
            for i in range(5):
                save_snapshot(td, [], [], ts=base + timedelta(minutes=i))
            removed = prune(td, keep=2)
            self.assertEqual(removed, 3)
            self.assertEqual(len(load_snapshots(td)), 2)


class AnalyzerTest(unittest.TestCase):
    def test_analyze_counts_and_edges(self):
        with tempfile.TemporaryDirectory() as td:
            ts = datetime(2026, 9, 17, 14, 0, 0, tzinfo=timezone.utc)
            save_snapshot(td, raw_markets(), [], ts=ts)
            # a second snapshot with a different timestamp
            from datetime import timedelta
            save_snapshot(td, raw_markets(), [], ts=ts + timedelta(minutes=5))
            stats = analyze(td, Config())
            self.assertEqual(stats["snapshots"], 2)
            self.assertEqual(stats["markets_total"], 8)
            # BTC + ETH pass the filters per snapshot (weather filtered: low liquidity,
            # MVE filtered: multivariate); only ETH has a net edge after fees
            self.assertEqual(stats["tradable"], 4)
            self.assertEqual(stats["opps_all"], 2)
            self.assertEqual(stats["opps_executable"], 2)
            self.assertEqual(stats["net_edge_cents_stats"]["max"], 5.0)
            self.assertIn("KXETHABOVE-26SEP18-4000", stats["top_tickers"])
            report = format_report(stats)
            self.assertIn("snapshots: 2", report)
            self.assertIn("top tickers", report)

    def test_empty_dir(self):
        with tempfile.TemporaryDirectory() as td:
            stats = analyze(td, Config())
            self.assertEqual(stats["snapshots"], 0)
            self.assertIn("nothing collected yet", format_report(stats))


if __name__ == "__main__":
    unittest.main()
