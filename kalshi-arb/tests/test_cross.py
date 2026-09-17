"""Tests for cross-venue (Kalshi <-> Polymarket) detection and execution."""
import json
import tempfile
import unittest
from pathlib import Path

from kalshi_arb.bot import run_cross_scan
from kalshi_arb.client import FixtureClient
from kalshi_arb.config import Config
from kalshi_arb.cross import (XV_A, XV_B, evaluate_pair, load_pairs,
                              pm_fee, suggest_matches)
from kalshi_arb.models import Market, kalshi_fee
from kalshi_arb.notifier import Notifier
from kalshi_arb.pm_client import FixturePmClient, PmMarket
from kalshi_arb.portfolio import PaperPortfolio

HERE = Path(__file__).parent / "fixtures"
KALSHI_MARKETS = json.loads((HERE / "markets_sample.json").read_text())["markets"]
PM_MARKETS = json.loads((HERE / "pm_sample.json").read_text())


def kalshi(ticker):
    return Market.from_api(next(d for d in KALSHI_MARKETS if d["ticker"] == ticker))


def pm(condition_id):
    return PmMarket.from_api(next(d for d in PM_MARKETS if d["conditionId"] == condition_id))


class DetectTest(unittest.TestCase):
    def setUp(self):
        self.cfg = Config()
        self.k = kalshi("KXETHABOVE-26SEP18-4000")     # yes_ask 0.45 / no_ask 0.46
        self.p = pm("0xpm_fixture_eth_4000")           # bid 0.44 / ask 0.47

    def test_xv_b_detected(self):
        # XV_B: NO@K 0.46 + YES@PM 0.47 = 0.93 -> gross 0.07
        p = load_pairs(self._tmp_map())
        o = evaluate_pair(p[0], self.k, self.p, self.cfg)
        self.assertIsNotNone(o)
        self.assertEqual(o.kind, XV_B)
        fees = kalshi_fee(0.46, 1, self.cfg.fee_rate) + pm_fee(0.47, 1, 0.05)
        self.assertAlmostEqual(o.fees, fees, places=6)
        self.assertAlmostEqual(o.net_edge, 0.07 - fees, places=6)
        self.assertGreater(o.net_edge, self.cfg.xv_min_net_edge)
        self.assertEqual(o.max_pairs, 180.0)  # limited by K's NO ask size

    def test_xv_a_absent_here(self):
        # XV_A: YES@K 0.45 + NO@PM (1-0.44)=0.56 -> 1.01 > 1 : no opportunity
        p = load_pairs(self._tmp_map())
        o = evaluate_pair(p[0], self.k, self.p, self.cfg)
        self.assertNotEqual(o.kind, XV_A)

    def test_no_opp_when_expensive(self):
        # XV_A needs p_bid <= 0.45 (K yes ask) and XV_B needs p_ask >= 0.54
        # (1 - K no ask) to be unprofitable
        p_exp = PmMarket(condition_id="x", question="q", status="open",
                         best_bid=0.44, best_ask=0.55, has_book=True,
                         volume_24h_usd=10000, liquidity_usd=20000)
        p = load_pairs(self._tmp_map())
        self.assertIsNone(evaluate_pair(p[0], self.k, p_exp, self.cfg))

    def test_pm_side_filters(self):
        p_thin = PmMarket(condition_id="x", question="q", status="open",
                          best_bid=0.44, best_ask=0.47, has_book=True,
                          volume_24h_usd=10, liquidity_usd=10)
        p = load_pairs(self._tmp_map())
        self.assertIsNone(evaluate_pair(p[0], self.k, p_thin, self.cfg))

    def _tmp_map(self):
        with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as f:
            json.dump({"pairs": [{"label": "ETH demo",
                                  "kalshi_ticker": "KXETHABOVE-26SEP18-4000",
                                  "pm_condition_id": "0xpm_fixture_eth_4000",
                                  "pm_fee_rate": 0.05}]}, f)
            return f.name


class LoadPairsTest(unittest.TestCase):
    def test_missing_file(self):
        self.assertEqual(load_pairs("/nonexistent/path.json"), [])

    def test_valid_file(self):
        with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as f:
            json.dump({"pairs": [
                {"label": "a", "kalshi_ticker": "K1", "pm_condition_id": "0x1"},
                {"label": "broken", "kalshi_ticker": "", "pm_condition_id": "0x2"},
            ]}, f)
            name = f.name
        pairs = load_pairs(name)
        self.assertEqual(len(pairs), 1)
        self.assertEqual(pairs[0].kalshi_ticker, "K1")


class SuggestTest(unittest.TestCase):
    def test_matching_pair_ranks_first(self):
        k_ms = [kalshi("KXETHABOVE-26SEP18-4000"), kalshi("KXBTCABOVE-26SEP17-60K")]
        p_ms = [pm("0xpm_fixture_eth_4000"), pm("0xpm_fixture_noise_1")]
        out = suggest_matches(k_ms, p_ms, top_n=3)
        self.assertTrue(out)
        top = out[0]
        self.assertEqual(top["kalshi_ticker"], "KXETHABOVE-26SEP18-4000")
        self.assertEqual(top["pm_condition_id"], "0xpm_fixture_eth_4000")


class RunCrossScanTest(unittest.TestCase):
    def setUp(self):
        self.td = tempfile.TemporaryDirectory()
        self.cfg = Config(state_file=str(Path(self.td.name) / "pf.json"))
        self.notifier = Notifier()
        with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as f:
            json.dump({"pairs": [{"label": "ETH demo",
                                  "kalshi_ticker": "KXETHABOVE-26SEP18-4000",
                                  "pm_condition_id": "0xpm_fixture_eth_4000",
                                  "pm_fee_rate": 0.05}]}, f)
            self.cfg.venue_map_file = f.name

    def tearDown(self):
        self.td.cleanup()

    def _k(self):
        return FixtureClient(KALSHI_MARKETS)

    def _pf(self):
        return PaperPortfolio(bankroll_usd=1000.0, cash_usd=1000.0)

    def test_executes_one_cross_trade_with_meta(self):
        pf = self._pf()
        s = run_cross_scan(self._k(), FixturePmClient(PM_MARKETS),
                           load_pairs(self.cfg.venue_map_file), pf, self.cfg, self.notifier)
        self.assertEqual(s["opps"], 1)
        self.assertEqual(len(pf.positions), 1)
        pos = pf.positions[0]
        self.assertEqual(pos.kind, XV_B)
        self.assertEqual(pos.meta.get("pm_condition_id"), "0xpm_fixture_eth_4000")
        # cost/pair = 0.46 + 0.47 + fees
        fees = kalshi_fee(0.46, 1) + pm_fee(0.47, 1, 0.05)
        self.assertAlmostEqual(pos.cost_usd / pos.pairs, 0.46 + 0.47 + fees, places=4)

    def test_second_scan_no_double_dip(self):
        pf = self._pf()
        run_cross_scan(self._k(), FixturePmClient(PM_MARKETS),
                       load_pairs(self.cfg.venue_map_file), pf, self.cfg, self.notifier)
        s2 = run_cross_scan(self._k(), FixturePmClient(PM_MARKETS),
                            load_pairs(self.cfg.venue_map_file), pf, self.cfg, self.notifier)
        self.assertEqual(len(pf.positions), 1)
        self.assertEqual(len(s2["traded"]), 0)

    def test_settlement_pays_dollar_per_pair(self):
        pf = self._pf()
        run_cross_scan(self._k(), FixturePmClient(PM_MARKETS),
                       load_pairs(self.cfg.venue_map_file), pf, self.cfg, self.notifier)
        edge = pf.positions[0].edge_usd
        # now the market closed with result "no" -> the NO leg pays
        closed = [dict(d) for d in KALSHI_MARKETS]
        for d in closed:
            if d["ticker"] == "KXETHABOVE-26SEP18-4000":
                d["status"] = "closed"
                d["result"] = "no"
        s = run_cross_scan(FixtureClient(closed), FixturePmClient(PM_MARKETS),
                           load_pairs(self.cfg.venue_map_file), pf, self.cfg, self.notifier)
        self.assertEqual(len(pf.open_positions), 0)
        self.assertAlmostEqual(pf.realized_pnl_usd, edge, places=4)

    def test_no_pairs_no_trades(self):
        pf = self._pf()
        s = run_cross_scan(self._k(), FixturePmClient(PM_MARKETS),
                           [], pf, self.cfg, self.notifier)
        self.assertEqual(s["pairs"], 0)
        self.assertEqual(len(pf.positions), 0)


if __name__ == "__main__":
    unittest.main()
