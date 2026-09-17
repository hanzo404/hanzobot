# چشم‌انداز ربات‌های سودده + منابع آموزشی گیت‌هاب — تحقیق دوم

> تحقیق با `gh` (اسکن ۸ دسته از ریپازیتوری‌های GitHub به‌ترتیب ستاره) + خواندن مستقیم READMEهای ~۲۰ ریپازیتوری کلیدی + جستجوی بازار تجاری و MEV. تاریخ: سپتامبر ۲۰۲۶

---

## ۱) واقعیت اول: «ربات سودده» چه شکلی هست؟

قبل از لیست، سه واقعیت که از داده‌ها روشن شد:

1. **در GitHub، ربات‌های open-source عمدتاً «فریمورک آموزشی + زیرساخت» هستند، نه جیب پول.** سود واقعی معمولاً از **پارامترهای خصوصی + زیرساخت** (سرعت، colocation، دسترسی به builder) می‌آید نه از خود کد. READMEهای معتبر این را صادقانه می‌گویند (مثلاً poly-maker: «Market making on Polymarket is competitive and can lose money... not a guaranteed-profitable product»).
2. **سود MEV/سندیویچ winner-take-most است.** داده‌های ۲۰۲۵–۲۲۶:
   - یک آپراتور (`jaredfromsubway.eth`) از مارس ۲۰۲ **۲۲+ میلیون دلار** سود سندیویچ جمع کرده و ~۷۰٪ تمام حملات سندیویچ اتریوم در ۲۰۲۵ مال اوست ([Plisio](https://plisio.net/crypto/mev-bot)).
   - میانگین سود هر سندیویچ در اتریوم تا اکتبر ۲۰۵ افت کرده به **~۳ دلار**؛ ⅓ botهای فعال در نقطه‌ی سربه‌سر و ۳۰٪ زیان‌ده‌اند ([ItisPay](https://itispay.com/blog/mev-bot)).
   - در Solana یک bot در ۳۰ روز: ۱.۵۵ میلیون تراکنس، ۸۸.۹٪ موفقیت، ۶۵,۸۸۰ SOL (۱۳.۴ میلیون دلار) سود؛ یک bot دیگر ۴۲٪ کل حجم سندیویچ Solana را دارد.
   - مجموع سود MEV روی همه بلاک‌چین‌ها از ۱ میلیارد دلار گذشت؛ «سودمندترین صندلی‌ها» مال تیم‌هایی‌اند که شبیه‌ساز اختصاصی + رابطه مستقیم با builder دارند.
   - **نتیجه:** هر رباتی که «بازگشت تضمینی روزانه» می‌فروشد، scam است (دسته‌ی AI-MEV scam در ۲۰۲ صدها هزار دلار از خُردها را درآورد).
3. **ربات‌های «تجاری خُرد» (3Commas و…) بیشتر فرمول‌های grid/DCA/copy هستند** که سودشان در بازارهای رِنج‌دار خوب و در رونددار بد است — و عمده سود به خود پلتفرم (کارمزد) می‌رسد.

---

## ۲) لیست ربات‌های open-source (به‌ترتیب دسته و اهمیت)

### A) چارچوب‌های اصلی (بیشترین ستاره = بیشترین یادگیری)

| ریپو | ستاره | چیست | چی ازش یاد بگیریم |
|---|---|---|---|
| [freqtrade/freqtrade](https://github.com/freqtrade/freqtrade) | ۵۴k | کامل‌ترین bot پایتونی: بک‌تست، بهینه‌سازی با ML، کنترل از طریق **Telegram و WebUI** | فرهنگ «اول Dry-Run»، ساختار استراتژی، مدیریت پول؛ مستندش مرجع استاندارد است |
| [hummingbot/hummingbot](https://github.com/hummingbot/hummingbot) | ۲۰k | فریمورک HFT/بازارسازی روی ۱۴۰+ صرافی CEX/DEX (حجم کاربران: **۳۴+ میلیارد دلار**) | معماری multi-venue، استراتژی‌های آماده (pure market making, avellaneda, cross-exchange) |
| [je-suis-tm/quant-trading](https://github.com/je-suis-tm/quant-trading) | ۱۰.۷k | مجموعه استراتژی‌های کم‌کم (momentum, breakout, reversal, **stat arb**، VIX و…) | کتابخانه ایده‌ی استراتژی؛ نقل‌قول‌ش معروف: «۵۱٪ درستی در ترید زیاد = سود» |
| [Drakkar-Software/OctoBot](https://github.com/Drakkar-Software/OctoBot) | ۶.۶k | bot رایگان با استراتژی‌های AI/Grid/DCA/TradingView روی ۱۵+ صرافی | الگوی «استراتژی به‌صورت JSON قابل‌تعریف» |
| [Superalgos/Superalgos](https://github.com/Superalgos/Superalgos) | ۵.۷k | پلتفرم بصری (no-code) ساخت bot | UX ساختار‌گراف استراتژی |
| [Lumiwealth/lumibot](https://github.com/Lumiwealth/lumibot) | ۲k | بک‌تست و اجرای استراتژی + **agentهای AI** (سهم/گزینش/کریپتو) | موج جدید: AI به‌عنوان لایه‌ی تصمیم روی فریمورک |
| [mementum/backtrader](https://github.com/mementum/backtrader) + [kernc/backtesting.py](https://github.com/kernc/backtesting.py) | ۲۳k/k | بک‌تستر | هر استراتژی پیش از live باید backtest شود |

### B) آربیتراژ کریپتو (CEX)

| ریپو | ستاره | نکته |
|---|---|---|
| [ccxt/binance-trade-bot](https://github.com/ccxt/binance-trade-bot) | ۸.۷k | ربات ساده‌ی ۲سکه‌ای با deploy یک‌کلیکه؛ مناسب الگوریتم ساده |
| [hzjken/crypto-arbitrage-framework](https://github.com/hzjken/crypto-arbitrage-framework) | ۷۰۵ | **بهترین الگوریتمی‌ که دیدم:** به‌جای brute-force روی همه مسیرها، با **Linear Programming (cplex)** بهترین مسیر چندپا را پیدا می‌کند + محاسبه‌ی بهینه‌ی حجم هر جفت با قیدها |
| [manu354/cryptocurrency-arbitrage](https://github.com/manu354/cryptocurrency-arbitrage) | ۱.۲k | محاسبه‌گر فرصت روی ۵۰+ بازار |
| [godzilla-foundation/godzilla-community](https://github.com/godzilla-foundation/godzilla-community) | ۳۷۳ | ⭐ **مهم‌ترین کشف این دور:** زیرساخت نهادی C++/Python برای **funding rate arb + market making**، latency میانی tick-to-trade **~۱۲۵ میکروثانیه**، طراحی برای colocation کنار matching engine. «not a retail bot — production-grade infrastructure». Whitepaper + بنچمارک قابل‌تکرار دارد |

### C) بازارسازی HFT

| ریپو | ستاره | نکته |
|---|---|---|
| [nkaz001/hftbacktest](https://github.com/nkaz001/hftbacktest) | ۴.۷k | ⭐ بک‌تستر HFT که **latency فید/سفارش و موقعیت صف سفارش (queue position)** را مدل می‌کند — بدون این‌ها بک‌تست HFT «حالمسی» است. خروجی: بک‌تست tick-by-tick + live bot (Binance Futures/Bybit) |
| [michaelgrosner/tribeca](https://github.com/michaelgrosner/tribeca) | ۴.۱k | bot بازارسازی node.js با واکنش < ۱ میلی‌ثانیه، Web UI و بک‌تستر داخلی |
| [ctubio/Krypto-trading-bot](https://github.com/ctubio/Krypto-trading-bot) | ۳.۷k | MM خودمیزبانی‌شده C++ |
| [fedecaccia/avellaneda-stoikov](https://github.com/fedecaccia/avellaneda-stoikov) | ۷۲۸ | پیاده‌سازی مدل A-S |
| [purefinance/mmb](https://github.com/purefinance/mmb) | ۶۱۸ | MM با Rust برای هر اکسچنج/بلاک‌چینی |
| [tspooner/rl_markets](https://github.com/tspooner/rl_markets) | ۳۴۹ | MM با **reinforcement learning** (تحقیقی) |

### D) بازارهای پیش‌بینی (داغ‌ترین و تازه‌ترین فضا)

| ریپو | ستاره | نکته |
|---|---|---|
| [aarora4/Awesome-Prediction-Market-Tools](https://github.com/aarora4/Awesome-Prediction-Market-Tools) | ۷۴۷ | ⭐ **فهرست آموزشی جامع**: APIها، botها، ابزارهای آربیتراژ، دیتا، منابع آموزشی برای Polymarket/Kalshi/Manifold — نقطه‌ی شروع عالی |
| [warproxxx/poly-maker](https://github.com/warproxxx/poly-maker) | ۱.۵k |  bot **maker-only** برای Polymarket CLOB V2. معماری‌اش درس‌دار است: کوئت post-only، **fair-value + inventory skew**، تخمین volatility/toxicity زنده، **regime machine** (برداشتن کوئت‌ها در لحظۀ خبر)، heartbeat dead-man switch، daily-loss **kill switch** |
| [rodlaf/KalshiMarketMaker](https://github.com/rodlaf/KalshiMarketMaker) | ۳۹۳ | ⭐ هر بازار یک **Avellaneda-Stoikov worker** مجزا؛ بازارچین‌کن با فیلتر volume/spread؛ invariant «پاک‌کردن سفارش‌ها قبل از حذف worker»؛ retry/backoff برای rate-limit؛ سقف پورتفولیوی سراسری |
| [radioman/polymarket-arbitrage-trading-bot](https://github.com/radioman/polymarket-arbitrage-trading-bot) | ۵۲۰ | ⚠️ **نمونه‌ی red flag:** لینک referral در README («click so your polymarket account will be boosted») — ببندید |
| [HarrierOnChain/Prediction-Markets-Trading-Bot-Toolkits](https://github.com/HarrierOnChain/Prediction-Markets-Trading-Bot-Toolkits) | ۴۴۸ | botهای Rust برای Polymarket/Kalshi؛ copy-trading production-ready + ۹ استراتژی دیگر |

### E) MEV / DeFi

| ریپو | ستاره | نکته |
|---|---|---|
| [paradigmxyz/artemis](https://github.com/paradigmxyz/artemis) | ۲.۹k | ⭐ **تمیزترین معماری MEV که دیدم** (از خود پارادایم): پایپ‌لاین سه‌لایه‌ی **Collector → Strategy → Executor**. Collector رویداد می‌گیرد (pending tx, new block)، Strategy فرصت تشخیص می‌دهد، Executor اجرا می‌کند |
| [solidquant/mev-templates](https://github.com/solidquant/mev-templates) | ۵۷۹ | ⭐ **بهترین منبع آموزشی MEV:** تمپلیت‌های خوانا Python/JS/Rust با یک آربیتراژ فلش‌لن DEX واقعی؛ workflow کامل: خواندن eventهای PairCreated → ساخت مسیر مثلثی → multicall `getReserves` روی ۶۰۰۰+ پول در ۱–۳ ثانیه → استریم بلوک/ترانز → شبیه‌سازی آفلاین → bundle به Flashbots |
| [BowTiedDevil/degenbot](https://github.com/BowTiedDevil/degenbot) | ۵۶۶ | هسته‌ی MEV با Rust + درایور Python (PyO3)؛ **معماری I/O-free** (تست‌پذیری)؛ پشتیبانی Uniswap V2/V3/V4، Curve، Balancer، Aave V3 |
| [jito-labs/mev-bot](https://github.com/jito-labs/mev-bot) | ۱.۲k | ربات رسمی Jito (Solana) |
| [haydenshively/New-Bedford / Nantucket](https://github.com/haydenshively/New-Bedford) | ۲۴۳/۱۹ | botهای لیکوئیدیشن Compound با flashswap/flashloan (کلاسیک‌ها) |
| [morpho-org/morpho-blue-liquidation-bot](https://github.com/morpho-org/morpho-blue-liquidation-bot) | ۱۱۵ | لیکوئیدیتور رسمی Morpho Blue |
| ⚠️ ریپوهای «solana sniper/copy bot» با t.me | — | **بیشترشون scam** (بخش ۴) |

### F) جدیدترها (AI + ترید)

| ریپو | ستاره | نکته |
|---|---|---|
| [tradermonty/claude-trading-skills](https://github.com/tradermonty/claude-trading-skills) | ۲.۸k | ⭐ **Skills برای Claude/agentها**: چک‌لیست مرور بازار، مدیریت ریسک، ژورنال ترید. فلسفه‌اش هوشمند است: «هدف **بهودر زدن تصمیم به AI نیست**، ساختار‌دادن به فرایند تصمیم است. این سیگنال‌فروشی نیست» |
| [joshyattridge/smart-money-concepts](https://github.com/joshyattridge/smart-money-concepts) | ۲k | کتابخانه‌ی Python برای ICT/SMC |
| [huseinzol05/Stock-Prediction-Models](https://github.com/huseinzol05/Stock-Prediction-Models) | ۹.۵k | مدل‌های ML/DL پیش‌بینی قیمت |

---

## ۳) لیست استراتژی‌های آماده‌ی رایگان

- [freqtrade/freqtrade-strategies](https://github.com/freqtrade/freqtrade-strategies) (۵.۵k★) — استراتژی‌های رایگان برای freqtrade؛ خودشان می‌گویند «نقطه‌ی شروع، نه ready-to-use».
- [iterativv/NostalgiaForInfinity](https://github.com/iterativv/NostalgiaForInfinity) (۳.۴k★) — مشهورترین استراتژی freqtrade. **نکات عملیاتی مستندش درس است:** ۶–۱۲ ترید هم‌زمان، ۴۰–۸۰ جفت، فقط جفت‌های استیبل‌کوین (نه BTC/ETH pair)، **سیاه‌کردن توکن‌های لوریجی**، timeframe باید ۵m، و سه فلگ خاص config (`use_exit_signal=true`, `exit_profit_only=false`, `ignore_roi_if_entry_signal=true`).
- استراتژی‌های hummingbot: pure market making، Avellaneda، cross-exchange arbitrage (در hummingbot.org).
- [je-suis-tm/quant-trading](https://github.com/je-suis-tm/quant-trading) — ده‌ها اسکریپت استراتژی با backtest.

---

## ۴) ️ مهم‌ترین درس ایمنی: شناسایی ربات‌های scam در GitHub

حین تحقیق چند الگوی کلاسیک کلاهبرداری دیدم که باید هرگز به آن‌ها پول/کل ندید:

| نشانه | نمونه‌ای که دیدم |
|---|---|
| «Hire me for custom development» + لینک Telegram در README | mortdeus/solana-copy-sniper-mev-trading-bot (۴.۵k★!) |
| لینک referral در README («click! so your account will be boosted») | radioman/polymarket-arbitrage-trading-bot |
| کلیدواژه‌ی SEO-stuffed در نام repo (تکرار ۵ باره‌ی «ethereum flashloan…») + «🆓 Free… premium verified» | ده‌ها ریپوی solana-sniper |
| «نتایج واقعی ۰ block» با اسکرین‌شات ولی بدون کد قابل‌اجرا | الگوی رایج |
| ستاره‌های خریداری‌شده (star farming) + «please star to motivate» | الگوی رایج |

**قوانین طلایی:** (۱) هر «بازگشت تضمینی» = scam؛ (۲) هر «verification fee» برای اجرای bot = scam؛ (۳) هرگز کل خصوصی به botی ندهید که کدش transparent نباشد؛ (۴) ستاره ≠ اعتبار — تاریخچه‌ی commit و issueها را بخوانید؛ (۵) ریپوهای قدیمی و راکد (gekko, zenbot) را به‌عنوان «آموزش معماری» بخوانید نه ابزار زنده.

---

## ۵) بازار تجاری (ربات‌های پولی که «کار می‌کنند»)

| پلتفرم | قیمت | مدل |
|---|---|---|
| [Pionex](https://blockster.com/crypto-trading-bots-in-2026-ranked-reviewed-compared-beginners-to-pros) | رایگان + 0.05٪ کارمزد | خود یک صرافی با ۱۶ bot داخلی (Grid, DCA, Arbitrage, TWAP) |
| 3Commas | ۲۰/۵۰/۱۴ دلار ماهانه | DCA/Grid/Signal multi-exchange |
| Bitsgap | رایگان/۲۹+ | Grid + Futures |
| Cryptohopper | رایگان/۲۹+ | cloud automation + **copy trading** |
| Coinrule | رایگان/۲۹.۹ | قوانین if-then |
| Gunbot | ۱۹۹–۵۰ دلار یک‌بار | لایسنس مادام‌العمر |
| Botهای داخلی صرافی‌ها | رایگان | Binance/Bybit/KuCoin/OKX (مثل Arbitrage Bot خودِ crypto.com) |
| ابزارهای Solana | %۱ تا هر ترید | Axiom, Banana Gun, Photon, BonkBot (کپی‌ترید + سنیپ + MEV protection) |

**درس:** مدل درآمدی این‌ها **کارمزد** است، نه سود bot — پس هر «بازگشت تضمینی‌»ای که در تبلیغات می‌بینید بافتزاری است.

---

## ۶) منابع آموزشی گیت‌هاب (نقشۀ یادگیری)

### الف) مستندات رسمی (اول این‌ها)
1. [freqtrade.io](https://www.freqtrade.io) — کامل‌ترین مستندات bot: از install تا ML-optimized strategies.
2. [hummingbot.org](https://hummingbot.org) — راهنماهای deployment و استراتژی‌های آماده.
3. [godzilla.dev/documentation + Whitepaper](https://github.com/godzilla-foundation/godzilla-community/blob/master/WHITEPAPER.md) — درس latency engineering (بنچمارک ۱۲۵μs قابل‌تکرار).
4. [hftbacktest.readthedocs.io](https://hftbacktest.readthedocs.io) — توتوریال‌های Grid HFT و queue position.
5. [iterativv.github.io/NostalgiaForInfinity](https://iterativv.github.io/NostalgiaForInfinity/) — مستندات عملیاتی یک استراتژی زنده.
6. [tradermonty.github.io/claude-trading-skills](https://tradermonty.github.io/claude-trading-skills/) — workflow AI در ترید.

### ب) فهرست‌های awesome (دروازه‌ی ورود)
- [Awesome-Prediction-Market-Tools](https://github.com/aarora4/Awesome-Prediction-Market-Tools) — کامل‌ترین دایرکتوری بازار پیش‌بینی (API، bot، آربیتراژ، آموزشی).
- [wilsonfreitas/awesome-quant](https://github.com/wilsonfreitas/awesome-quant) — کلاسیک دنیای quant (کتابخانه، داده، کتاب).
- [freqtrade-strategies](https://github.com/freqtrade/freqtrade-strategies) — استراتژی‌های واقعی با backtest.

### ج) الگوریتم‌ها و معماری (درس فنی)
- **LP برای مسیر آرب:** [hzjken framework](https://github.com/hzjken/crypto-arbitrage-framework) — cplex به‌جای brute-force.
- **پایپ‌لاین MEV:** [artemis](https://github.com/paradigmxyz/artemis) — Collector/Strategy/Executor.
- **فلش‌لن آرب واقعی:** [solidquant templates](https://github.com/solidquant/mev-templates).
- **بازارسازی امن:** [poly-maker](https://github.com/warproxxx/poly-maker) — kill switch، heartbeat، regime machine، toxicity.
- **A-S روی Kalshi:** [KalshiMarketMaker](https://github.com/rodlaf/KalshiMarketMaker) — worker به‌ازای هر بازار + invariant پاک‌سازی.
- **تست‌پذیری:** [degenbot](https://github.com/BowTiedDevil/degenbot) — I/O-free Rust core + Python driver.
- **بک‌تست صادق:** [hftbacktest](https://github.com/nkaz001/hftbacktest) — latency و queue position.

### د) پپرها (عمق نظری)
- MEV: [Unmasking MEV](https://arxiv.org/abs/2105.13400) و مرورهای ۲۰۲۴–۲۲۶ (در [plisio](https://plisio.net/crypto/mev-bot) خلاصه‌شده).
- انرژی: [NREL/OSTI papers](https://www.osti.gov/servlets/purl/1358239).
- آرب دوگانه‌نماد: [ResearchGate paper](https://www.researchgate.net/publication/343847873_The_Law_of_One_Price_and_Arbitrage_on_China's_Dual-Listings_in_Hong_Kong_and_New_York).

---

## ۷) نقشۀ عملیاتی پیشنهادی برای hanzobot (از جمعِ همه‌ی یادگرفته‌ها)

### فاز ۰ — اصول (از همه‌ی READMEهای معتبر)
1. **Dry-Run / Paper-Mode اول** — هر فریمورک معتبر این را اجباری می‌داند (freqtrade, poly-maker).
2. **Kill switch** — سقف زیان روزانه که کل bot را خاموش می‌کند (poly-maker).
3. **Heartbeat / dead-man** — اگه bot کرش کند، سفارش‌های باقی‌مانده خودکار کنسل شوند.
4. **Edge بعد از هزینه** — هیچ فرصتی اجرا نشود مگر net-edge مثبت باشد (همۀ منابع، از P2P تا MEV، یک‌صدا).
5. **لاگ و ژورنال کامل** هر تصمیم (فرهند claude-trading-skills و freqtrade).

### فاز ۱ — بازار پیش‌بینی (کم‌رقابت‌ترین فزای زنده)
- الگوبرداری مستقیم از **KalshiMarketMaker** (انتخاب بازار با فیلتر volume/spread + worker A-S هر بازار) + **poly-maker** (post-only، inventory skew، regime machine).
- فهرست ابزار از **Awesome-Prediction-Market-Tools**.
- بک‌تست روی داده‌های تاریخی order book قبل از live.

### فاز ۲ — Funding/Basis arb (پایدارترین بازده)
- شروع: اسکنر ساده‌ی funding rate + هشدار (هر ۸ ساعت).
- پیشرفت: اجرای خودکار spot+perp با re-balance — و مطالعه‌ی معماری **godzilla** برای latency.

### فاز ۳ — StatArb/Grid (برای سرمایه‌ی کم)
- freqtrade + freqtrade-strategies + NFI (به‌عنوان الگو، نه کپی‌پیست).
- بک‌تست با backtesting.py / hftbacktest (اگر HFT شد).

### فاز ۴ (اختیاری، DeFi)
- اگر وارد MEV شد: اول **artemis + solidquant** را خط‌به‌خط بخوانید، بعد با **anvil** (شبیه‌ساز محلی) تست کنید — هرگز مستقیم روی mainnet.

### از این‌ها دور بایست (با داده)
- ربات‌های «solana sniper free» با t.me → **scam** (بخش ۴).
- سندیویچ MEV به‌عنوان استراتژی → ۳ دلار میانگین سود، ۳۰٪ زیان، ۷۰٪ سهم یک نفر.
- کپی‌پیست استراتژی‌های آماده → خودِ صاحبانش می‌گویند «ready-to-use نیستند».

---

## ۸) خلاصه‌ی یک‌جمله‌ای هر منبع کلیدی

| منبع | یک جمله |
|---|---|
| freqtrade | بهترین نقطه‌ی شروع پایتونی با Telegram و بک‌تست |
| hummingbot | استاندارد صنعتی بازارسازی open-source |
| godzilla | نشان می‌دهد funding arb نهادی یعنی latency ۱۲۵μs و colocation |
| poly-maker | الگوی «بازارسازی ایمن» با kill switch و regime machine |
| KalshiMarketMaker | الگوی «هر بازار یک worker A-S» |
| artemis | الگوی تمیز MEV: Collector→Strategy→Executor |
| solidquant | تنها آربیتراژ فلش‌لن DEX «واقعی و قابل‌اجرا» در قالب آموزشی |
| hftbacktest | بک‌تست HFT بدون queue position دروغ است |
| hzjken | LP/cplex = مسیر آرب چندپا بدون brute-force |
| NFI | نمونه‌ی عملیاتی پیرامترهای یک استراتژی زنده |
| claude-trading-skills | AI = ساختار برای تصمیم، نه جایگزین تصمیم |
| Awesome-Prediction-Market-Tools | دروازه‌ی ورود به بازار پیش‌بینی |
