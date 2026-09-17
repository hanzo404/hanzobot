"""End-to-end tests of the scan cycle using the offline fixture client."""
import json
import tempfile
import unittest
from pathlib import Path

from kalshi_arb.bot import run_scan
from kalshi_arb.client import FixtureClient
from kalshi_arb.config import Config
from kalshi_arb.notifier import Notifier
from kalshi_arb.portfolio import PaperPortfolio

FIXTURE = Path(__file__).parent / "fixtures" / "markets_sample.json"


def load_fixture():
    data = json.loads(FIXTURE.read_text())
    return FixtureClient(data["markets"])


class RunScanTest(unittest.TestCase):
    def setUp(self):
        self.td = tempfile.TemporaryDirectory()
        self.cfg = Config(state_file=str(Path(self.td.name) / "pf.json"))
        self.notifier = Notifier()  # console only

    def tearDown(self):
        self.td.cleanup()

    def _pf(self):
        return PaperPortfolio(bankroll_usd=self.cfg.bankroll_usd,
                              cash_usd=self.cfg.bankroll_usd)

    def test_first_scan_executes_one_trade(self):
        pf = self._pf()
        s = run_scan(load_fixture(), pf, self.cfg, self.notifier)
        self.assertEqual(s["scanned"], 4)
        self.assertEqual(s["opportunities"], 1)
        self.assertEqual(s["new_trades"], 1)
        self.assertEqual(s["traded"], ["KXETHABOVE-26SEP18-4000"])
        # stake = min(25, 1000*0.05) = 25 -> 26 pairs @ 0.95 = 24.70
        self.assertEqual(len(pf.positions), 1)
        self.assertAlmostEqual(pf.cash_usd, 1000.0 - 24.70, places=4)
        self.assertAlmostEqual(s["bankroll"], pf.cash_usd + pf.positions[0].edge_usd, places=4)
        self.assertTrue(Path(self.cfg.state_file).exists())

    def test_second_scan_does_not_double_dip(self):
        pf = self._pf()
        run_scan(load_fixture(), pf, self.cfg, self.notifier)
        s2 = run_scan(load_fixture(), pf, self.cfg, self.notifier)
        self.assertEqual(s2["new_trades"], 0)   # same quote combo already seen
        self.assertEqual(len(pf.positions), 1)  # no duplicate position

    def test_btc_market_edge_killed_by_fees(self):
        # 0.53 + 0.46 = 0.99 looks like a 1c arb, but fees eat it
        pf = self._pf()
        s = run_scan(load_fixture(), pf, self.cfg, self.notifier)
        self.assertNotIn("KXBTCABOVE-26SEP17-60K", s["traded"])

    def test_halted_portfolio_trades_nothing(self):
        pf = self._pf()
        pf.realized_day_usd = -100.0
        s = run_scan(load_fixture(), pf, self.cfg, self.notifier)
        self.assertTrue(s["halted"])
        self.assertIn("reason", s)
        self.assertEqual(len(pf.positions), 0)


if __name__ == "__main__":
    unittest.main()
