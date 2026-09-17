"""Tests for signal detection and market filtering."""
from datetime import datetime, timedelta, timezone
import unittest

from kalshi_arb.config import Config
from kalshi_arb.models import Market
from kalshi_arb.signals import COMBO_BUY, COMBO_SELL, evaluate_market, filter_markets, scan

NOW = datetime(2026, 9, 17, 14, 0, tzinfo=timezone.utc)


def mk(ticker="T", yes_ask=0.45, no_ask=0.46, yes_bid=0.44, no_bid=0.53,
       size=100.0, liq=10000.0, vol=5000.0, mtype="binary", mve="",
       status="active", close_in_h=None, result=""):
    close = NOW + timedelta(hours=close_in_h) if close_in_h is not None else None
    return Market(
        ticker=ticker, title=f"market {ticker}", event_ticker="E", status=status,
        market_type=mtype, result=result, close_time=close, mve_collection_ticker=mve,
        yes_bid=yes_bid, yes_ask=yes_ask, yes_ask_size=size,
        no_bid=no_bid, no_ask=no_ask, no_ask_size=size,
        liquidity_usd=liq, volume_24h_usd=vol,
    )


class FilterTest(unittest.TestCase):
    def setUp(self):
        self.cfg = Config()

    def test_excludes_multivariate(self):
        ms = [mk(mve="KXMVE-R"), mk(ticker="ok")]
        self.assertEqual([m.ticker for m in filter_markets(ms, self.cfg, NOW)], ["ok"])

    def test_excludes_low_liquidity_and_volume(self):
        ms = [mk(liq=100), mk(vol=10)]
        self.assertEqual(filter_markets(ms, self.cfg, NOW), [])

    def test_excludes_expiring_soon(self):
        ms = [mk(close_in_h=0.2), mk(ticker="ok", close_in_h=24)]
        self.assertEqual([m.ticker for m in filter_markets(ms, self.cfg, NOW)], ["ok"])

    def test_excludes_non_binary_and_closed(self):
        ms = [mk(mtype="multivariate"), mk(status="closed")]
        self.assertEqual(filter_markets(ms, self.cfg, NOW), [])


class ComboBuyTest(unittest.TestCase):
    def setUp(self):
        self.cfg = Config()

    def test_clear_edge_detected(self):
        # gross = 1 - (0.45+0.46) = 0.09 ; fees = 0.02+0.02 = 0.04 ; net = 0.05
        o = evaluate_market(mk(), self.cfg)
        self.assertIsNotNone(o)
        self.assertEqual(o.kind, COMBO_BUY)
        self.assertAlmostEqual(o.gross_edge, 0.09, places=6)
        self.assertAlmostEqual(o.fees, 0.04, places=6)
        self.assertAlmostEqual(o.net_edge, 0.05, places=6)
        self.assertEqual(o.max_pairs, 100.0)

    def test_thin_edge_killed_by_fees(self):
        # gross = 0.01, fees = 0.04 -> negative, must NOT signal
        self.assertIsNone(evaluate_market(mk(yes_ask=0.53, no_ask=0.46), self.cfg))

    def test_no_signal_below_min_edge(self):
        cfg = Config(min_net_edge=0.10)
        self.assertIsNone(evaluate_market(mk(), cfg))

    def test_requires_tradable_quotes(self):
        self.assertIsNone(evaluate_market(mk(yes_ask=0.0), self.cfg))
        self.assertIsNone(evaluate_market(mk(no_ask=0.0), self.cfg))


class ComboSellTest(unittest.TestCase):
    def setUp(self):
        self.cfg = Config()

    def test_sell_side_signal(self):
        # yes_bid + no_bid = 1.02 > 1 -> COMBO_SELL
        o = evaluate_market(mk(yes_bid=0.55, no_bid=0.47, yes_ask=0.56, no_ask=0.48), self.cfg)
        self.assertIsNotNone(o)
        self.assertEqual(o.kind, COMBO_SELL)
        self.assertAlmostEqual(o.gross_edge, 0.02, places=6)

    def test_buy_side_wins_when_stronger(self):
        # buy edge 0.05 net vs sell edge 0.01 -> report the buy
        o = evaluate_market(
            mk(yes_ask=0.45, no_ask=0.46, yes_bid=0.50, no_bid=0.41), self.cfg)
        self.assertEqual(o.kind, COMBO_BUY)


class ScanTest(unittest.TestCase):
    def test_sorted_by_net_edge(self):
        cfg = Config()
        ms = [
            mk(ticker="small", yes_ask=0.49, no_ask=0.50),   # gross 0.01 -> killed by fees
            mk(ticker="big", yes_ask=0.30, no_ask=0.40),     # gross 0.30
            mk(ticker="mid", yes_ask=0.40, no_ask=0.45),     # gross 0.15
        ]
        opps = scan(ms, cfg, NOW)
        tickers = [o.market.ticker for o in opps]
        self.assertEqual(tickers, ["big", "mid"])


if __name__ == "__main__":
    unittest.main()
