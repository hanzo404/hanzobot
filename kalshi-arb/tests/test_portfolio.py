"""Tests for the paper portfolio: sizing, settlement, kill switch, persistence."""
import os
import tempfile
import unittest
from pathlib import Path

from kalshi_arb.config import Config
from kalshi_arb.models import Market
from kalshi_arb.portfolio import PaperPortfolio
from kalshi_arb.risk import RiskManager, stake_for
from kalshi_arb.signals import COMBO_BUY, evaluate_market


def mk(ticker="T", yes_ask=0.45, no_ask=0.46, size=1000.0):
    m = Market(ticker=ticker, title="t", event_ticker="E", status="active",
               market_type="binary", result="", close_time=None,
               mve_collection_ticker="", yes_bid=0.44, yes_ask=yes_ask,
               yes_ask_size=size, no_bid=0.53, no_ask=no_ask, no_ask_size=size,
               liquidity_usd=100000, volume_24h_usd=100000)
    return m


class SizingTest(unittest.TestCase):
    def test_stake_capped_by_max_stake(self):
        cfg = Config(max_stake_usd=25.0, stake_pct_of_bankroll=0.05)
        opp = evaluate_market(mk(), cfg)
        stake = stake_for(opp, cfg, bankroll=10000)
        self.assertEqual(stake, 25.0)

    def test_stake_capped_by_book(self):
        cfg = Config(max_stake_usd=10000.0, stake_pct_of_bankroll=0.5)
        opp = evaluate_market(mk(size=10.0), cfg)
        stake = stake_for(opp, cfg, bankroll=10000)
        self.assertLessEqual(stake, 10.0 * opp.cost_per_pair)


class PortfolioTest(unittest.TestCase):
    def setUp(self):
        self.cfg = Config()
        self.pf = PaperPortfolio(bankroll_usd=1000.0, cash_usd=1000.0)

    def test_open_and_edge_locked(self):
        opp = evaluate_market(mk(), self.cfg)
        pos = self.pf.open_combo_buy(opp, stake_for(opp, self.cfg, 1000.0))
        self.assertIsNotNone(pos)
        self.assertEqual(pos.status, "open")
        self.assertAlmostEqual(pos.edge_usd, pos.pairs - pos.cost_usd, places=4)
        self.assertLess(self.pf.cash_usd, 1000.0)
        self.assertEqual(self.pf.trades, 1)

    def test_settlement_pays_dollar_per_pair(self):
        opp = evaluate_market(mk(), self.cfg)
        pos = self.pf.open_combo_buy(opp, stake_for(opp, self.cfg, 1000.0))
        closed = Market(ticker="T", title="t", event_ticker="E", status="closed",
                        market_type="binary", result="yes", close_time=None,
                        mve_collection_ticker="", yes_bid=0, yes_ask=0,
                        yes_ask_size=0, no_bid=0, no_ask=0, no_ask_size=0,
                        liquidity_usd=0, volume_24h_usd=0)
        settled = self.pf.settle_if_closed(closed)
        self.assertIsNotNone(settled)
        self.assertEqual(settled.status, "settled")
        self.assertAlmostEqual(settled.realized_usd, pos.edge_usd, places=4)
        self.assertAlmostEqual(self.pf.realized_pnl_usd, pos.edge_usd, places=4)
        self.assertAlmostEqual(self.pf.realized_day_usd, pos.edge_usd, places=4)
        self.assertEqual(len(self.pf.open_positions), 0)

    def test_no_settlement_without_result(self):
        opp = evaluate_market(mk(), self.cfg)
        self.pf.open_combo_buy(opp, stake_for(opp, self.cfg, 1000.0))
        open_still = Market(ticker="T", title="t", event_ticker="E", status="active",
                            market_type="binary", result="", close_time=None,
                            mve_collection_ticker="", yes_bid=0, yes_ask=0,
                            yes_ask_size=0, no_bid=0, no_ask=0, no_ask_size=0,
                            liquidity_usd=0, volume_24h_usd=0)
        self.assertIsNone(self.pf.settle_if_closed(open_still))

    def test_kill_switch_trips_on_daily_loss(self):
        self.pf.realized_day_usd = -60.0  # beyond the 50 USD default limit
        tripped = self.pf.apply_kill_switch(self.cfg.daily_max_loss_usd)
        self.assertTrue(tripped)
        self.assertTrue(self.pf.halted)
        # risk manager must refuse new trades while halted
        rm = RiskManager(self.cfg, self.pf)
        decision = rm.check(evaluate_market(mk(), self.cfg), 10.0)
        self.assertFalse(decision.allowed)
        self.assertIn("kill switch", decision.reason)

    def test_kill_switch_auto_resumes_next_day(self):
        self.pf.halted = True
        self.pf.realized_day_usd = -60.0
        self.pf.day = "1999-01-01"
        self.pf._roll_day()
        self.assertFalse(self.pf.halted)
        self.assertAlmostEqual(self.pf.realized_day_usd, 0.0)

    def test_max_open_positions(self):
        cfg = Config(max_open_positions=1)
        pf = PaperPortfolio(bankroll_usd=1000.0, cash_usd=1000.0)
        rm = RiskManager(cfg, pf)
        pf.open_combo_buy(evaluate_market(mk(), cfg), 20.0)
        d = rm.check(evaluate_market(mk(ticker="U"), cfg), 20.0)
        self.assertFalse(d.allowed)
        self.assertIn("max open positions", d.reason)


class PersistenceTest(unittest.TestCase):
    def test_roundtrip(self):
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / "state" / "pf.json"
            pf = PaperPortfolio(bankroll_usd=1000.0, cash_usd=975.3)
            pf.open_combo_buy(evaluate_market(mk(), Config()), 25.0)
            pf.save(path)
            pf2 = PaperPortfolio.load(path)
            self.assertAlmostEqual(pf2.cash_usd, pf.cash_usd, places=4)
            self.assertEqual(len(pf2.positions), 1)
            self.assertEqual(pf2.positions[0].status, "open")
            self.assertAlmostEqual(pf2.positions[0].edge_usd, pf.positions[0].edge_usd)


if __name__ == "__main__":
    unittest.main()
