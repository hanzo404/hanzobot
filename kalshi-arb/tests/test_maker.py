"""Tests for maker-combo detection and the paper fill simulation."""
import json
import tempfile
import unittest
from pathlib import Path

from kalshi_arb.bot import run_scan
from kalshi_arb.client import FixtureClient
from kalshi_arb.config import Config
from kalshi_arb.models import Market
from kalshi_arb.notifier import Notifier
from kalshi_arb.portfolio import PaperPortfolio
from kalshi_arb.signals import COMBO_BUY, MAKER_COMBO, evaluate_market

FIXTURE = Path(__file__).parent / "fixtures" / "markets_maker.json"


def mk(ticker="T", yes_bid=0.50, no_bid=0.45, yes_ask=0.56, no_ask=0.55,
       yes_bid_size=100.0, no_bid_size=100.0):
    return Market(ticker=ticker, title="t", event_ticker="E", status="active",
                  market_type="binary", result="", close_time=None,
                  mve_collection_ticker="", yes_bid=yes_bid, yes_ask=yes_ask,
                  yes_ask_size=100.0, yes_bid_size=yes_bid_size,
                  no_bid=no_bid, no_ask=no_ask, no_ask_size=100.0,
                  no_bid_size=no_bid_size,
                  liquidity_usd=100000, volume_24h_usd=100000)


class MakerSignalTest(unittest.TestCase):
    def setUp(self):
        self.cfg = Config()

    def test_maker_combo_detected_at_bids(self):
        # gross = 1 - (0.50+0.45) = 0.05
        # maker fees = ceil(0.0175*0.5*0.5*100)/100 + ceil(0.0175*0.45*0.55*100)/100
        #            = 0.01 + 0.01 = 0.02  -> net = 0.03
        o = evaluate_market(mk(), self.cfg)
        self.assertIsNotNone(o)
        self.assertEqual(o.kind, MAKER_COMBO)
        self.assertAlmostEqual(o.gross_edge, 0.05, places=6)
        self.assertAlmostEqual(o.fees, 0.02, places=6)
        self.assertAlmostEqual(o.net_edge, 0.03, places=6)
        self.assertEqual(o.yes_price, 0.50)   # fills at the BID, not the ask

    def test_no_signal_without_bid_sizes(self):
        # missing bid-size field -> safe default: no maker signal
        self.assertIsNone(evaluate_market(mk(yes_bid_size=0, no_bid_size=0), self.cfg))

    def test_thin_maker_edge_rejected(self):
        # gross = 0.01, maker fees = 0.02 -> negative
        self.assertIsNone(evaluate_market(mk(yes_bid=0.52, no_bid=0.47), self.cfg))

    def test_taker_combo_still_preferred_when_better(self):
        # taker combo net 0.05 (asks 0.45/0.46) beats maker net 0.03
        o = evaluate_market(mk(yes_bid=0.50, no_bid=0.45, yes_ask=0.45, no_ask=0.46),
                            self.cfg)
        self.assertEqual(o.kind, COMBO_BUY)


class MakerFillSimulationTest(unittest.TestCase):
    def setUp(self):
        self.td = tempfile.TemporaryDirectory()
        self.cfg = Config(state_file=str(Path(self.td.name) / "pf.json"),
                          maker_fill_scans=2)
        self.cfg.venue_map_file = str(Path(self.td.name) / "nope.json")
        self.notifier = Notifier()
        self.client = FixtureClient(json.loads(FIXTURE.read_text())["markets"])

    def tearDown(self):
        self.td.cleanup()

    def _pf(self):
        return PaperPortfolio(bankroll_usd=1000.0, cash_usd=1000.0)

    def test_matures_after_consecutive_scans(self):
        pf = self._pf()
        s1 = run_scan(self.client, pf, self.cfg, self.notifier)
        self.assertEqual(s1["new_trades"], 0)          # warmed once, not mature
        self.assertEqual(s1.get("maker_warmed"), 1)
        s2 = run_scan(self.client, pf, self.cfg, self.notifier)
        self.assertEqual(s2["new_trades"], 1)          # matured on 2nd scan
        self.assertEqual(pf.positions[0].kind, MAKER_COMBO)
        # filled at the bid: cost/pair = 0.50 + 0.45 + 0.02 = 0.97
        self.assertAlmostEqual(pf.positions[0].cost_usd / pf.positions[0].pairs,
                               0.97, places=4)

    def test_warm_resets_when_condition_breaks(self):
        pf = self._pf()
        run_scan(self.client, pf, self.cfg, self.notifier)   # warm = 1
        # a scan where the market disappeared from the feed -> warm reset
        empty = FixtureClient([])
        s = run_scan(empty, pf, self.cfg, self.notifier)
        self.assertEqual(s.get("maker_warmed"), 0)
        self.assertEqual(len(pf.positions), 0)


if __name__ == "__main__":
    unittest.main()
