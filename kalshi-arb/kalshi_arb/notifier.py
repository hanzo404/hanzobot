"""Notifications: always to the console log; optionally to Telegram.

Telegram is optional and best-effort — a failed send never breaks the
scan loop.
"""
from __future__ import annotations

import logging
import urllib.parse
import urllib.request

log = logging.getLogger("kalshi_arb.notify")


class Notifier:
    def __init__(self, token: str = "", chat_id: str = "") -> None:
        self.token = token
        self.chat_id = chat_id
        self._seen: set[tuple] = set()

    def _send_telegram(self, text: str) -> None:
        if not (self.token and self.chat_id):
            return
        try:
            url = f"https://api.telegram.org/bot{self.token}/sendMessage"
            body = urllib.parse.urlencode(
                {"chat_id": self.chat_id, "text": text, "disable_web_page_preview": "true"}
            ).encode()
            req = urllib.request.Request(url, data=body)
            urllib.request.urlopen(req, timeout=10).read()
        except Exception as e:  # noqa: BLE001 - notifications must never kill the loop
            log.warning("telegram notify failed: %s", e)

    def opportunity(self, opp) -> bool:
        """Deduped alert for a newly detected opportunity. Returns True if sent."""
        key = (opp.market.ticker, round(opp.yes_price, 4), round(opp.no_price, 4))
        if key in self._seen:
            return False
        self._seen.add(key)
        text = (
            f"🎯 {opp.kind} {opp.market.ticker}\n"
            f"{opp.market.title[:120]}\n"
            f"YES@{opp.yes_price:.3f} + NO@{opp.no_price:.3f}\n"
            f"gross {opp.gross_edge * 100:.2f}c | fees {opp.fees * 100:.2f}c "
            f"| NET {opp.net_edge * 100:.2f}c | max {int(opp.max_pairs)} pairs"
        )
        log.info("SIGNAL %s", text.replace("\n", " | "))
        self._send_telegram(text)
        return True

    def cross_opportunity(self, o) -> bool:
        """Deduped alert for a cross-venue opportunity (XV_A / XV_B)."""
        key = ("XV", o.kalshi.ticker, o.kind, round(o.k_price, 4), round(o.p_price, 4))
        if key in self._seen:
            return False
        self._seen.add(key)
        text = (
            f"🎯 {o.kind} {o.pair.label}\n"
            f"Kalshi {o.kalshi.ticker}@{o.k_price:.3f} + "
            f"Polymarket {o.pair.pm_condition_id[:10]}…@{o.p_price:.3f}\n"
            f"gross {o.gross_edge * 100:.2f}c | fees {o.fees * 100:.2f}c "
            f"| NET {o.net_edge * 100:.2f}c | max {int(o.max_pairs)} pairs"
        )
        log.info("CROSS-SIGNAL %s", text.replace("\n", " | "))
        self._send_telegram(text)
        return True

    def trade(self, pos, stake: float) -> None:
        text = (
            f"📝 PAPER FILL {pos.kind} x{pos.pairs:g} @ {pos.market_ticker} — "
            f"stake ${stake:.2f}, locked edge +${pos.edge_usd:.4f}"
        )
        log.info("TRADE %s", text)
        self._send_telegram(text)

    def kill_switch(self, reason: str) -> None:
        text = f"🛑 KILL SWITCH TRIPPED — {reason}. Trading halted until next day."
        log.error(text)
        self._send_telegram(text)
