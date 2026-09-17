"""Tests for models (API parsing + fee model)."""
import unittest

from kalshi_arb.models import Market, kalshi_fee


class FeeModelTest(unittest.TestCase):
    def test_fee_mid_price(self):
        # 0.07 * 1 * 0.5 * 0.5 = 0.0175 -> rounded UP to cent = 0.02
        self.assertEqual(kalshi_fee(0.5, 1, 0.07), 0.02)

    def test_fee_scales_with_qty(self):
        # 0.07 * 10 * 0.5 * 0.5 = 0.175 -> 0.18
        self.assertEqual(kalshi_fee(0.5, 10, 0.07), 0.18)

    def test_fee_zero_at_edges(self):
        self.assertEqual(kalshi_fee(1.0, 5), 0.0)
        self.assertEqual(kalshi_fee(0.0, 5), 0.0)
        self.assertEqual(kalshi_fee(0.5, 0), 0.0)


class MarketParseTest(unittest.TestCase):
    def test_from_api(self):
        m = Market.from_api({
            "ticker": "T-1", "title": "Will X happen?", "event_ticker": "E-1",
            "status": "active", "market_type": "binary", "result": "",
            "close_time": "2026-09-17T23:59:00Z",
            "mve_collection_ticker": "",
            "yes_bid_dollars": "0.5200", "yes_ask_dollars": "0.5300",
            "yes_ask_size_fp": "250.00",
            "no_bid_dollars": "0.4700", "no_ask_dollars": "0.4600",
            "no_ask_size_fp": "180.00",
            "liquidity_dollars": "15000.00", "volume_24h_fp": "22000.00",
        })
        self.assertEqual(m.yes_ask, 0.53)
        self.assertEqual(m.no_ask, 0.46)
        self.assertEqual(m.yes_ask_size, 250.0)
        self.assertTrue(m.has_tradable_quotes)
        self.assertTrue(m.is_binary)
        self.assertFalse(m.is_multivariate)
        self.assertIsNotNone(m.close_time)

    def test_empty_strings_parse_to_zero(self):
        m = Market.from_api({"ticker": "T-2", "yes_ask_dollars": "", "no_ask_dollars": ""})
        self.assertFalse(m.has_tradable_quotes)

    def test_mve_detected(self):
        m = Market.from_api({"ticker": "T-3", "mve_collection_ticker": "KXMVE-R"})
        self.assertTrue(m.is_multivariate)


if __name__ == "__main__":
    unittest.main()
