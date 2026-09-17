# ۵۰ استراتژی و سبک ربات آربیتراژ — تحقیق جامع

> تحقیق انجام‌شده در سپتامبر ۲۰۶ (۳۰+ جستجوی مستند، ۶۰+ منبع مختلف)
> هدف: شناسایی استراتژی‌های مختلف آربیتراژ، نحوه کار هرکدام، منابع، ریسک‌ها و سختی پیاده‌سازی رباتی

---

## نمای کلی دسته‌بندی‌ها

| دسته | شماره‌ها | تعداد |
|---|---|---|
| A) کریپتو / صرافی‌های متمرکز (CEX) | ۱–۱۰ | ۱۰ |
| B) بازارسازی و جریان سفارش | ۱۱–۱۴ | ۴ |
| C) DeFi / MEV / آن‌چین | ۱۵–۲۶ | ۱۲ |
| D) مالی سنتی (TradFi) | ۲۷–۴۳ | ۱۷ |
| E) انرژی و کالایی | ۴۴–۴۶ | ۳ |
| F) بازارهای پیش‌بینی و شرط‌بندی | ۴۷–۵۰ | ۴ |
| Bonus) آربیتراژ دنیای واقعی (e-commerce و…) | B1–B5 | ۵ |

---

## A) کریپتو / صرافی‌های متمرکز

### ۱) آربیتراژ اسپات بین‌صرافی (Cross-Exchange Spot Arbitrage)
- **مکانیزم:** خرید توکن در صرافی A که ارزان‌تر است و فروش هم‌زمان در صرافی B که گران‌تر است. سود = اختلاف قیمت − (کارمزد ترید × ۲ − کارمزد واریز/برداشت).
- **منابع:** [Shift Markets](https://www.shiftmarkets.com/blog/cross-exchange-arbitrage-explained) — [99Bitcoins](https://99bitcoins.com/analysis/crypto-arbitrage-bots/)
- **نکات کلیدی:** نیاز به حساب‌های پیش‌فاندشده در چند صرافی (واپس‌ناپذیری سرمایه). مارجین‌ها ریز است (در مثال 99Bitcoins: ۱۴۰ دلار سرمایه → ۱.۴۰ دلار سود). رقابت با ربات‌های میلی‌ثانیه‌ای.
- **سختی رباتی:** متوسط. با کتابخانه ccxt و Websocket دو صرافی قابل پیاده‌سازی.

### ۲) آربیتراژ مثلثی (Triangular Arbitrage)
- **مکانیزم:** سه ترید در یک صرافی روی سه جفت (مثلاً USDT→BTC→ETH→USDT)؛ اگر حاصل‌ضرب نرخ‌ها > ۱ + کارمزدها باشد، سود بدون ریسک جهت دارد.
- **منابع:** [Ledger Academy](https://www.ledger.com/academy/glossary/triangular-arbitrage) — [OwnR Wallet](https://ownrwallet.com/blog/what-is-triangular-arbitrage-in-crypto/) — [OSL](https://www.osl.com/hk-en/academy/article/crypto-triangular-arbitrage-opportunities-for-risk-free-profit)
- **نکات کلیدی:** چون همه کار در یک صرافی است، ریسک ترانسفر پول نیست — فقط سرعت و کارمزد مهم است. فرصت‌ها در چند ثانیه بسته می‌شوند؛ ربات‌های HFT این فضا را تسخیر کرده‌اند.
- **سختی رباتی:** متوسط تا سخت (نیاز به order book لحظه‌ای + اجرای هم‌زمان ۳ سفارش).

### ۳) آربیتراژ نرخ فیوندینگ / دلتا-نوترال (Funding Rate Arbitrage)
- **مکانیزم:** خرید اسپات + فروش پرپ‌یویتال هم‌نوتیالی. وقتی فیوندینگ مثبت است، لانگ‌ها به شورت‌ها پول می‌دهند؛ شما طرف دریافت‌کننده‌ای‌د و ریسک جهت صفر است.
- **منابع:** [Sharpe AI](https://www.sharpe.ai/learn/funding-rate-arbitrage) — [Kraken Learn](https://www.kraken.com/learn/futures-trading-funding-rate-arbitrage) — [ArbitrageScanner](https://arbitragescanner.io/blog/crypto-funding-rate-arbitrage-strategy-guide)
- **نکات کلیدی:** بازده سالانه ۸–۴۰٪ در بازارهای صعودی. ریسک‌ها: **برگشت فیوندینگ به منفی**، ریسک لیکوئید شدن پایه فیوچرز در نوسان شدید، و عدم تطابق کمیت دو پا (Quantity mismatch). «دلتا-نوترال ≠ ضد لیکوئید».
- **سختی رباتی:** **کم** — کندترین و مناسب‌ترین استراتژی برای شروع؛ سرعت میلی‌ثانیه‌ای لازم نیست.

### ۴) کش‌اند‌کری / ترید بیس (Cash & Carry / Basis Trade)
- **مکانیزم:** خرید اسپات + شورت فیوچرز با **انقضا (dated futures)**. سود از بیس (اختلاف قیمت فیوچرز و اسپات) است که در انقضا صفر می‌شود؛ در مقابل فیوندینگ دوره‌ای، اینجا یک‌بار سود در expiry.
- **منابع:** [BackQuant](https://www.backquant.com/learn/basis-trade) — [Mudrex](https://mudrex.com/learn/basis-trading-crypto-cash-and-carry/) — [Spark Glossary](https://www.spark.money/glossary/perpetual-futures)
- **نکات کلیدی:** در بازار صعودی بیس مثبت است (فیوچرز گران‌تر). بازده تاریخی ۱۰–۳۰٪ سالانه. ریسک اصلی: کارمزد دو پا + اسلیپیج، و نیاز به کلینینگ/رول در انقضاهای فصلی.
- **سختی رباتی:** کم تا متوسط (معمولاً یک‌بار باز می‌کنید تا expiry نگه می‌دارید).

### ۵) آربیتراژ بین‌صرافی اسپات-پرپ (Cross-Venue Basis / Bot داخلی صرافی)
- **مکانیزم:** اسپات در یک صرافی، پرپ در صرافی دیگر (یا استفاده از Bot داخلی صرافی که هر دو پا را یکجا می‌زند). سود از اختلاف قیمت اسپات/پرپ بین صرافی‌ها.
- **منابع:** [Crypto.com — Arbitrage Bot](https://help.crypto.com/en/articles/9755982-arbitrage-trading-bot) — [99Bitcoins](https://99bitcoins.com/analysis/crypto-arbitrage-bots/)
- **نکات کلیدی:** اسناد رسمی crypto.com دو ریسک عمده را شفاف می‌گوید: **فیوندینگ معکوس** (وقتی نرخ به منفی می‌رود، شورت شروع به پرداخت می‌کند) و **عدم تطابق کمیت** دو پا به‌خاطر تفاوت ارز کارمزد.
- **سختی رباتی:** کم (اگر صرافی Bot داخلی بدهد) تا متوسط.

### ۶) آربیتراژ P2P (P2P → Spot)
- **مکانیزم:** خرید استیبل‌کوین در بازار P2P با قیمت زیر بازار (مثلاً USDT با ۸۷.۲ RUB وقتی اسپات ۸۸.۵ است) و فروش در اسپات. در بازارهایی با کنترل ارز (روسیه، نایجریه، و…) اسپردها گاهی ۵–۱۵٪ است.
- **منابع:** [SpreadScan](https://spreadscan.com/en/blog/p2p-crypto-arbitrage) — [WunderTrading](https://wundertrading.com/journal/en/learn/article/binance-arbitrage) — [XBankang](https://blogs.xbankang.com/p2p-arbitrage-strategy-turn-exchange-gaps-into-profit/)
- **نکات کلیدی:** ریسک اصلی **ریسک متقابل (Counterparty)** و بلوک شدن کارت بانکی (در بازارهای CIS). سرعت تأیید واریز ۵–۱۵ دقیقه است؛ پول در دست‌نرس می‌ماند.
- **سختی رباتی:** سخت از نظر عملیاتی (احراز هویت، واریزهای بانکی) اما تشخیص فرصت کاملاً خودکار است (اسکنر + اعلان تلگرامی).

### ۷) آربیتراژ روش پرداخت (Payment Method Arbitrage)
- **مکانیزم:** در P2P، فروشنده‌ها روش‌های پرداخت پرریسک (تحت خطر چارج‌بک) را با تخفیف می‌فروشند و روش‌های امن (نقد، واریز مستقیم) را گران‌تر؛ اسپرد بین روش‌ها سود است.
- **منبع:** [SpreadScan](https://spreadscan.com/en/blog/p2p-crypto-arbitrage) (Strategy 3)
- **نکات کلیدی:** در عمل با #۶ یکپارچه می‌شود؛ ربات باید اسپرد هر روش پرداخت را جداگانه بایگانی کند.

### ۸) آربیتراژ دی‌پِگ استیبل‌کوین (Stablecoin Depeg Arbitrage)
- **مکانیزم:** هرگاه USDC/USDT/DAI از ۱ دلار عبور کنند (مثلاً ۰.۹۹$)، خرید ارز ارزان‌قیمت و فروش گران‌قیمت در DEX/CEX. در بحران SVB (مارس ۲۰۳) USDC تا ۰.۸۸$ افت کرد و Bot‌های بازسازی سود بزرگی گرفتند.
- **منابع:** [Bitsgap](https://bitsgap.com/blog/dex-arbitrage-with-stablecoins-in-2026-where-the-opportunity-is-and-what-can-go-wrong) — [Eco](https://eco.com/support/en/articles/11506305-what-is-a-stablecoin-usdc-usdt-dai-and-how-they-work-in-2026) — [P2P.Army](https://p2p.army/en/cc/stablecoin_arbitrage)
- **نکات کلیدی:** **پهنای اسپرد گاهی یعنی دی‌پِگ در حال وقوع است** (نه فرصت بی‌خطر). پنجره‌ها چند ثانیه‌ای‌اند و MEV Bot‌ها حریف اصلی‌اند. مکانیزم ردمپشن (AP‌ها) قیمت را در چند دقیقه به ۱ برمی‌گرداند.
- **سختی رباتی:** سخت (رقابت شدید) ولی اسکنر و اعلان‌دهی آن برای سرمایه‌گذار خُرد ارزشمند است.

### ۹) شنیپینگ لیستینگ جدید CEX (New Listing Front-Running)
- **مکانیزم:** ربات صفحه انouncements صرافی (Binance/KuCoin) را poll می‌کند و به‌محض اعلام لیستینگ، توکن را در صرافی دیگر (مثلاً Gate.io) می‌خرد و پس از لیستینگ روی صرافی بزرگ می‌فروشد.
- **منابع:** [Reddit — تجربۀ یک رباتساز](https://www.reddit.com/r/CryptoCurrency/comments/ruep0r/finally_did_it_i_made_a_crypto_trading_bot_that/) — [CryptoNinjas — مطالعۀ آماری](https://www.cryptoninjas.net/exchange/study-cex-listing-effects/)
- **نکات کلیدی:** مطالعۀ CryptoNinjas (۲۰۲۶): لیستینگ‌های بزرگ در **میانگین ۵۴٪ پامپ**، ۳۷٪ توکن‌ها دقیقاً لحظۀ لیستینگ ATH می‌زنند و ۸۹٪ بعداً دامپ می‌شوند (میانگین −۵۲٪). یعنی پامپ واقعی ولی ناهمواهنج؛ بعضی توکن‌ها پیش از اعلام هم پامپ می‌کنند.
- **سختی رباتی:** متوسط (polling + اجرای زیر ۵ ثانیه). ریسک: پامپ قبل از اعلام و دامپ بعدی.

### ۱۰) آربیتراژ تاخیری / استاله‌کوئت (Latency Arbitrage / Stale Quotes)
- **مکانیزم:** دیدن تغییر قیمت در یک منبع داده سریع‌تر و ترید روی کوئت قدیمی منبع آهسته‌تر (صرافی، بروکر، یا اکسچنج دیگر) پیش از به‌روز شدن آن. پنجره: چند میکروثانیه تا ۲۰۰ میلی‌ثانیه.
- **منابع:** [Quantt](https://www.quantt.co.uk/resources/latency-arbitrage-explained) — [Match-Prime](https://match-prime.com/news/latency-arbitrage-strategies-part-2) — [BJF Trading Group](https://bjftradinggroup.com/latency-arbitrage/)
- **نکات کلیدی:** در بازارهای سنتی نیاز به **colocation در دیتاسنتر اکسچنج** و FIX protocol دارد. در فارکس (بروکرهای آهسته) سود ۰.۵–۳ پیپ در ترید ولی **آی‌تی بروکرها این پترن را تشخیص و حساب را محدود می‌کنند**.
- **سختی رباتی:** **بسیار سخت** — جنگ زیرساخت و سرمایه سنگین.

### (۱۱) ترید خبری HFT (News-Based Trading)
- **مکانیزم:** همان ارکیتراژ تاخیری ولی محرکش خبر با تأثیر بالا است (NFP, CPI, نرخ بهره). فید سریع قیمت خبری را چند میلی‌ثانیه قبل از بروکر/اکسچنج می‌بیند و در جهت حرکت سفارش می‌زند.
- **منبع:** [Match-Prime — Part II](https://match-prime.com/news/latency-arbitrage-strategies-part-2) (بخش News Price Arbitrage)
- **نکات کلیدی:** بزرگ‌ترین اختلاف قیمت دقیقاً در لحظۀ خبر اتفاق می‌افتد؛ ریسک: برگشت سریع و اسپرد گسترده در لحظۀ خبر.

---

## B) بازارسازی و جریان سفارش

### ۱۱) بازارسازی با مدل Avellaneda-Stoikov (Market Making)
- **مکانیزم:** کوئت زدن دو طرف (bid/ask) حول قیمت میده. مدل A-S با سه پارامتر (موجودی q، زمان تا پایان سشن T−t، ضریب ریسک γ) **قیمت رزرو** و **اسپرد بهینه** را محاسبه می‌کند تا ریسک موجودی مدیریت شود.
- **منابع:** [Hummingbot — راهنمای A-S](https://medium.com/hummingbot/a-comprehensive-guide-to-avellaneda-stoikovs-market-making-strategy-102d64bf5df6) — [Cube Exchange](https://www.cube.exchange/what-is/hummingbot-trading) — [AlgoBuddy Review](https://getalgobuddy.com/articles/hummingbot)
- **نکات کلیدی:** مشکل سخت‌تر از انتخاب اسپرد، **مدیریت موجودی (Inventory)** است: اگر فقط bidها فیل شوند و بازار بریزد، ضرر مارکت‌تو‌مارکت از اسپردها بیشتر است. Hummingbot (اپن‌سورس، Apache) رایج‌ترین فریمورک است. «ریسک استراتژی را در بازارهای رونددار کشف می‌کنید: یک روز ضرر = یک ماه سود اسپرد».
- **سختی رباتی:** متوسط (فریمورک آماده دارید؛ پیرامترسازی سخته).

### ۱۲) بازارسازی بین‌صرافی (Cross-Exchange Market Making)
- **مکانیزم:** کوئت در صرافی A و هم‌زمان **هدج در صرافی B**؛ اسپرد دو صرافی را می‌خورید و ریسک جهت را با پای دوم خنثی می‌کنید.
- **منبع:** [Cube Exchange](https://www.cube.exchange/what-is/hummingbot-trading) (جدول مقایسه MM تک‌صرافی/چندصرافی/آرب خالص)
- **نکات کلیدی:** نیاز به موجودی در هر دو صرافی، سرعت بالا، و مدیریت شکست پای هج (اگر یک پا فیل شود و پای دوم نرسد).
- **سختی رباتی:** سخت‌تر از MM تک‌صرافی.

### ۱۳) پیش‌بینی جریان سفارش (Order Flow Anticipation)
- **مکانیزم:** شناسایی سفارش‌های بزرگ نهادی از order book (imbalance، سفارش‌های حبابی که فیل نمی‌شوند) و ترید در جهت جریان پیش از رسیدن قیمت نهایی.
- **منبع:** [Quantt — جدول مقایسۀ HFT](https://www.quantt.co.uk/resources/latency-arbitrage-explained) (ردیف Order Anticipation)
- **نکات کلیدی:** مرز میان statarb و front-running است؛ اخلاقی‌اش بحث‌برانگیز. در کریپتو نسخه‌اش **معمولاً در CEXها با WebSocket bookTicker** انجام می‌شود.
- **سختی رباتی:** سخت (مدل‌سازی داده + سرعت).

### ۱۴) دنبال‌کردن نهنگ‌ها / کپی‌تریدینگ آن‌چین (Whale Tracking / Copy Trading)
- **مکانیزم:** ربات از طریق WebSocket/مempool تراکنش‌های جابجای جیب‌های «پول هوشمند» را دیکد می‌کند و در چند میلی‌ثانیه همین ترید را از طریق روتر (Jupiter و…) کپی می‌زند. فیلترهای ورود: درصد هلد deployer، تعداد سوشال، درصد سنیپرهای توکن.
- **منابع:** [GitHub — Solana whale trade bot](https://github.com/teckscribe/Solana-whale-trade-bot) — [WalletMaster — ابزارهای کپی‌ترید](https://www.walletmaster.tools/blog/best-solana-copy-trading-tools/) — [Hyperbot](https://hyperbot.network/track-monitor)
- **نکات کلیدی:** ابزارهای موجود: GMGN (فید پول هوشمند + کپی تا ۱۰ ولت)، Axiom (بازار خالص، کمیسیون ۱٪)، Cielo Finance (مانیتورینگ چندنوبتی). ریسک: **خودتان تبدیل به exit liquidity می‌شوید** (نهنگ می‌فروشد، شما می‌خرید).
- **سختی رباتی:** متوسط (ریپازیتوری‌های اپن‌سورس خوب موجود است).

---

## C) DeFi / MEV / آن‌چین

### ۱۵) آربیتراژ اتمیک DEX-to-DEX
- **مکانیزم:** اگر ETH/USDC در Uniswap ارزان‌تر از SushiSwap باشد، در **یک تراکنس** (یک بلوک) خرید در ارزان و فروش در گران انجام می‌شود؛ اگرترد شکست بخورد کل چیز revert می‌شود (ریسک فقط gas).
- **منابع:** [Nadcab](https://www.nadcab.com/blog/flash-loans-in-arbitrage) — [TechZar](https://www.techzarinfo.com/blogs/crypto-flash-loan-arbitrage-bot-development-guide)
- **نکات کلیدی:** طبق داده‌های نقل‌شده در Nadcab، آرب DEX-to-DEX حدود **۳۳٪ کل MEV اتریوم** است. رقابت بسیار شدید (botها روی مقولات جیتو/فلش‌بوتس با bundle کار می‌کنند).
- **سختی رباتی:** سخت (Solidity + فرستادن bundle به builder + بهینه‌سازی gas).

### ۱۶) آربیتراژ با فلش‌لن (Flash Loan Arbitrage)
- **مکانیزم:** وام بدون وثیقه از Aave (کارمزد ۰.۰۹٪) → خرید از DEX ارزان → فروش در DEX گران → بازپرداخت وام؛ همه در یک تراکنس. سرمایه اولیه فقط gas.
- **منابع:** [TechZar](https://www.techzarinfo.com/blogs/crypto-flash-loan-arbitrage-bot-development-guide) — [Kirchain](https://www.kirchainlabs.com/blog/crypto-flash-loan-arbitrage-bot-development-company/)
- **نکات کلیدی:** فلش‌لن **آرب را با سرمایه نامحدود ممکن می‌کند** ولی «ریسک‌ساز» را حذف نمی‌کند: اگر در لحظۀ اجرای بلوک قیمت برگردد، transaction revert می‌شود و فقط gas از دست می‌رود. بازاریابی‌های «۱۰–۲۵٪ ماهانه ریسک‌ساز» اغراق‌آمیزند.
- **سختی رباتی:** سخت.

### ۱۷) سندیویچ حمله (MEV Sandwich Attack)
- **مکانیزم:** رصد mepool عمومی؛ وقتی سوآپ بزرگ ریتیل دیده می‌شود: (۱) قبل از او بخر (front-run)، (۲) سفارش او قیمت را بالا ببرد، (۳) بلافاصله بعد از او بفروش (back-run). سود مستقیم از جیب قربانی.
- **منابع:** [Onchain Diary](https://paragraph.com/@onchaindiary/sandwich-attacks-explained) — [Mintarex](https://mintarex.com/en/blog/mev-maximum-extractable-value-explained-avoid)
- **نکات کلیدی:** **اخلاقاً و در بسیاری حوزه‌ها در معرض ریسک قانونی است** — به‌عنوان رباتساز، دفاعش (Flashbots Protect، CoW Swap، slippage تنگ) را یاد بگیرید تا ربات‌های شما قربانی نشوند. مورد واقعی: botی با ۵ میلیون دلار برای ~۷۰۰ دلار سود هک شد (حادثۀ Flashbots relay ۲۰۲ — [BlockSec](https://blocksec.com/blog/harvesting-mev-bots-by-exploiting-vulnerabilities-in-flashbots-relay)).
- **سختی:** (به‌عنوان مهاجم) سخت + ریسک‌دار. (به‌عنوان دفاع) آسان.

### ۱۸) بک‌رانینگ (Back-running)
- **مکانیزم:** درج تراکنس بلافاصله بعد از یک تراکنس سودده دیگر در همان بلوک (مثلاً پس از یک لیکویید یا سوآپ بزرگ) و بهره‌مندی از اثر قیمت آن.
- **منابع:** [Onchain Diary](https://paragraph.com/@onchaindiary/sandwich-attacks-explained) — [Mintarex](https://mintarex.com/en/blog/mev-maximum-extractable-value-explained-avoid)
- **نکات کلیدی:** بک‌رانینگ کم‌خطرتر از سندیویچ است (نهنگ را هدف نمی‌گیرد)؛ در Solana با **Jito bundles** و در اتریوم با Flashbots پیاده می‌شود.
- **سختی رباتی:** سخت.

### ۱۹) Bot لیکوئیدیشن DeFi (Liquidation Bot)
- **مکانیزم:** رصد حلقه‌های وام Aave؛ وقتی Health Factor < ۱ شود، با فلش‌لن بخشی از بدهی (تا ۵۰٪) را می‌پردازید و **وثیقه + بنوس لیکوئیدیشن** را دریافت می‌کنید. سود = بنوس − (فلش‌لن fee + gas).
- **منابع:** [GitHub — Aave v3 liquidation bot](https://github.com/thomasxiaodongwu/aave-v3-liquidation-bot) — [GitHub — Aave-Liquiditor](https://github.com/0xnavarro/Aave-Liquiditor)
- **نکات کلیدی:** معماری رایج: Price Monitor + Health Factor Scanner + Executor با فلش‌لن + محاسبه‌کنندۀ سود. بهینه‌سازی gas با باندل کردن چند لیکوئید در یک فلش‌لن.
- **سختی رباتی:** سخت (رقابت با botهای حرفه‌ای؛ حاشیه سود کم و race به gas).

### ۲۰) سوءاستفاده از تاخیر اوراکل (Oracle Delay / Stale Price Exploit)
- **مکانیزم:** اوراکل‌های قیمت (Chainlink و…) هر X بلوک آپدیت می‌شوند؛ اگر قیمت بازار بین دو آپدیت جهش کند، موقعیت‌ها با قیمت قدیمی سنجیده می‌شوند → لیکوئیدیشن‌های «غیرمنصف» که bot با فلش‌لن می‌گیرد.
- **منبع:** [GitHub — Aave v3 liquidation bot](https://github.com/thomasxiaodongwu/aave-v3-liquidation-bot) (استراتژی Oracle Delay Exploitation)
- **نکات کلیدی:** در عمل با #۱۹ یکی است ولی محرکش متفاوت است. ریسک: خطای در محاسبه = gas از رفته.
- **سختی رباتی:** بسیار سخت.

### ۲۱) آربیتراژ بین‌زنجیره‌ای (Cross-Chain Bridge Arbitrage)
- **مکانیزم:** قیمت همان توکن در دو چین متفاوت است (مثلاً USDC در Arbitrum vs Polygon)؛ خرید در ارزان، بریج با Stargate/Across/Hop (۱–۵ دقیقه، ۰.۰۴–۰.۰۶٪)، فروش در گران.
- **منابع:** [Thrive](https://thrive.fi/blog/defi/cross-chain-bridge-arbitrage) — [PocketOption](https://pocketoption.com/blog/en/interesting/trading-strategies/cross-chain-arbitrage/) — [KuCoin](https://www.kucoin.com/knowledge-base/Analysis/what-is-cross-chain-arbitrage-in-crypto)
- **نکات کلیدی:** هزینه‌های پنهان: fee بریج + gas مبدأ/مقصد + swap + slippage؛ «اسپرد ۰.۵٪ می‌تواند −۰.۲٪ شود». سرمایه معنادار ≥ ۱۰ هزار دلار. ریسک قرارداد هوشمند بریج.
- **سختی رباتی:** متوسط تا سخت (معمولاً با سرمایه پیش‌نصب در هر دو چین انجام می‌شود تا انتظار بریج نباشد).

### ۲۲) آربیتراژ LST (stETH/ETH و مشتقات استیکینگ)
- **مکانیزم:** LSTها گاهی از زیربنا دی‌پِگ می‌کنند (مثلاً stETH < ETH). خرید LST ارزان + فروش ETH + (در صورت امکان) تبدیل برگشتی، یا بهره‌مندی از بنوس بازگشت peg.
- **منابع:** [Decrypt](https://decrypt.co/105258/how-ethereum-stakers-on-lido-finance-are-trading-the-merge) — [Eco — sUSDe](https://eco.com/support/en/articles/15002228-ethena-susde-vs-usde-yield-mechanism-explained)
- **نکات کلیدی:** قبل از The Merge (۲۰۲) stETH تا زیر ۰.۹۹ ETH معامله شد و آرب‌کارها از دی‌پِگ سود گرفتند. امروز Ethena (USDe/sUSDe) همان ساختار **بیس‌ترید نهادی را توکنیزه** کرده — مطالعه‌اش درکت از دلتا-نوترال عمیق می‌کند.
- **سختی رباتی:** کم تا متوسط (پنجره‌های دی‌پِگ نادرند ولی سودشان بزرگ است).

### ۲۳) سنیپینگ لانچ توکن (Pump.fun / Raydium Launch Sniping)
- **مکانیزم:** سنیپینگ توکن‌ها روی bonding curve Pump.fun **قبل از** مهاجرت به Raydium؛ فیلترهای ورود: deployer < ۱۰٪، سوشال‌های واقعی، درصد سنیپر < ۴۰٪. اجرا با MEV protection (Jito) و priority fee + چندولت.
- **منابع:** [Banana Gun — راهنمای سنیپینگ](https://blog.bananagun.io/blog/how-to-snipe-pump-fun-tokens-before-they-migrate-to-raydium) — [Reddit r/solana — تجربه‌های لانچ](https://www.reddit.com/r/solana/comments/1gqtrsx/how_to_make_a_successful_launch_on_pumpfun/)
- **نکات کلیدی:** slippage پیشنهادی ۵–۷٪. ریسک اصلی **rug pull** و بودن شما به‌عنوان exit liquidity. روی Solana با Jito bundles سرعت تعیین‌کننده است.
- **سختی رباتی:** متوسط (ابزارهای تجاری مثل Banana Gun/Axiom وجود دارند؛ نسخه open-source هم هست).

### ۲۴) فارم ایردراپ (Airdrop Farming / Sybil)
- **مکانیزم:** ساخت چندین ولت + فعالیت شبیه‌ساز روی پروتکل‌ها (سواپ، بریج، staking) برای کسب elegibility ایردراپ؛ ضد-sybil با random کردن timing/مقادیر و **جداکردن منبع واریز** هر ولت.
- **منابع:** [Cointelegraph — گزارش sybil](https://cointelegraph.com/news/token-airdrops-targeted-farm-accounts-sybil-attacks) — [Coronium — معماری ۶ستونی](https://www.coronium.io/blog/airdrop-farming-proxy-guide-2026)
- **نکات کلیدی:** پروتکل‌ها با **cluster کردن منبع واریز + زمان‌بندی + fingerprint** فارمرها را ردیابی می‌کنند. هزینه gas/بریج می‌تواند از پاداش بیشتر باشد. **ریسک حقوقی و ToS بالایی دارد** — فقط برای درک اکوسیستم، نه پیشنهاد.
- **سختی رباتی:** متوسط (فنی) اما ریسک‌ساز از نظر اعتباری/حقوقی.

### ۲۵) اپتایمایزر ییلد فارمینگ (Auto-Compounder / Vault Rotation)
- **مکانیزم:** رصد APY صدها vault (Meteora، Raydium، Orca…)؛ به‌محض افت APY یا ورود vault بهتر، سرمایه را جابه‌جا + کومپاند خودکار پاداش‌ها.
- **منبع:** [GitHub — Solana Yield Farming Optimizer](https://github.com/Solana-Yield-Farming-Optimizer/solana-yield-farming-optimizer)
- **نکات کلیدی:** ادعای «تا ۳۵٪ ییلد بیشتر از فارم دستی». ریسک: impermanent loss LP‌ها + slippage در هر جابه‌جایی + ریسک قرارداد vault جدید.
- **سختی رباتی:** کم تا متوسط (مناسب‌ترین DeFi استراتژی برای شروع، چون سرعت حساس نیست).

### ۲۶) آربیتراژ بین‌مارکت‌پلیس NFT + مینت‌سنیپینگ
- **مکانیزم:** (الف) خرید NFT لیست‌شده در OpenSea و فروش هم‌قیمت/گران‌تر روی blur در میلی‌ثانیه (با فلش‌لن برای سرمایه)؛ (ب) سنیپ کردن mint‌های پرنایز (Rarity) در قیمت floor؛ (ج) مینت‌آرب: خرید در مینت عمومی و فروش در ۲۴–۷۲ ساعت اول.
- **منابع:** [ChainScore Labs](https://chainscorelabs.com/blog/nft-market-cycles-art-utility-and-culture/nft-market-cycles-and-psychology/the-future-of-nft-flipping-automated-and-emotionless) — [Blocklr](https://blocklr.com/guides/nft-flipping-strategies/) — [AltcoinInvestor](https://altcoininvestor.com/nft-flipping-strategy-2026/)
- **نکات کلیدی:** Botهایی مثل Tensorians و NFTPERP همین کار را ۲۴/۷ با RPC اختصاصی و mepool خصوصی (Flashbots Protect) می‌کنند. بازار NFT از ۲۰۲ خنک‌تر شده ولی اسپرد بین مارکت‌پلیس‌ها هنوز هست.
- **سختی رباتی:** سخت (سرعت + زیرساخت).

---

## D) مالی سنتی (TradFi)

### ۲۷) آربیتراژ ادغام / ریسک‌آرب (Merger Arbitrage)
- **مکانیزم:** پس از اعلام M&A، سهم target با **اسپرده** زیر قیمت پیشنهادی معامله می‌شود (مثلاً ۷٪). خرید target + (در معامله سهمی) شورت acquirer در نسبت توافق. سود = جمع‌شدن اسپرد در انقضای معامله؛ بازده ≈ نرخ بی‌ریسک + پریمیوم ریسک (~۳٪).
- **منابع:** [Carmignac](https://www.carmignac.com/en-ch/articles/a-look-at-the-merger-arbitrage-strategy-2519-9361) — [NBDB](https://nbdb.ca/learning-centre/understanding-stock-market/alternative-investments/merger-arbitrage-strategy.html) — [ReturnStacked](https://www.returnstacked.com/merger-arbitrage/) — [S&P Merger Arb Index](https://www.spglobal.com/spdji/en/indices/dividends-factors/sp-merger-arbitrage-index/)
- **نکات کلیدی:** ۹۰٪+ معاملات به سرانجام می‌رسند ولی **اگر deal شکست بخورد، target سقوط سنگینی می‌کند** (ریسک دم‌پهن). اسناد Van Tassel (2016) نشان می‌دهند قیمت‌های options می‌توانند احتمال موفقیت deal را پیش‌بینی کنند.
- **سختی رباتی:** متوسط (داده‌های M&A + مدل‌سازی احتمال شکست؛ سرعت مهم نیست).

### ۲۸) آربیتراژ اسپین‌آف (Spinoff Arbitrage)
- **مکانیزم:** در اسپین‌آف، سهام‌دار parent بلافاصله سهم شرکت جدید را دریافت می‌کند. در روز‌های اولیه نقدشوندگی پایین و اسپرد قیمت parent/subsidiary غیرمفید است؛ آرب‌کار با long/short ترکیبی نسبت درست را برمی‌گرداند.
- **منابع:** [MergerArbitrageLimited — glossary](https://mergerarbitragelimited.com/finance-and-investment-glossary/spinoff/) — [IB Interview Questions — انواع تفکیک](https://ibinterviewquestions.com/blog/spin-off-carve-out-transactions-guide)
- **نکات کلیدی:** تفاوت سه ساختار: **spin-off** (تقسیم بدون نقد، tax-free) vs **carve-out** (فروش سهام اقلیت با IPO، نقدینگی برای parent) vs **split-off** (تبادلی). ریسک: exchange ratio نادرست در هفته اول = فرصت.
- **سختی رباتی:** کم تا متوسط (فرصت‌های نادر ولی کم‌رقابت).

### ۲۹) آربیتراژ اوراق قابل‌تبدیل (Convertible Bond Arbitrage)
- **مکانیزم:** خرید CB (که option گران‌قیمت در خود دارد) + شورت سهام زیربنایی به‌اندازۀ delta → **long gamma، delta-nuetral** می‌شوید. با هر نوسان، re-hedge یعنی «فروش در بالا و خرید در پایین» → سود از volatility + کوپن.
- **منابع:** [RiskHub](https://riskhub.org/blogs/convertible-arbitrage-explained) — [Alpha-Maven](https://alpha-maven.com/learn/convertible-arbitrage-strategy) — [Financial Modeling](https://www.financial-modeling.com/convertible-bond-arbitrage-models-delta-neutral-credit-spread-decomposition/)
- **نکات کلیدی:** جدول hedge ratio: deep-OTM → ۴۰–۵۰٪ (هفته‌ای re-hedge)، ATM → ۶۰–۷۰٪ (روزانه)، ITM → ۷۰–۸۰٪ (intraday). ریسک‌های پنهان: **ریسک اعتباری CB + نسیلیدیتی + اهرم** که در بحران هم‌زمان منفجر می‌شوند.
- **سختی رباتی:** سخت (مدل قیمت‌گذاری CB + re-hedge خودکار).

### ۳۰) آربیتراژ وولاتیلیتی (Volatility Arbitrage)
- **مکانیزم:** مقایسۀ implied volatility (IV) با realized/expected (RV). اگر IV > RV: شورت option + delta-hedge. اگر IV < RV: لانگ option. سود از برگشت وولاتیلیتی به میانگین + زمان‌فروش.
- **منابع:** [QuestDB](https://questdb.com/glossary/volatility-arbitrage-strategies/) — [Strike Money](https://www.strike.money/options/volatility-arbitrage)
- **نکات کلیدی:** شکل‌های عملی: long/short vol، relative value vol (ضربه/انقضا/توکن‌های مرتبط)، dispersion trading. ریسک: **جیپ قیمت** (IV درست بود ولی بازار پرش می‌کرد) + هزینه hedging.
- **سختی رباتی:** سخت (نیاز به مدل RV + اجرای option).

### ۳۱) وارینس‌سوآپ و dispersion (Variance Swaps)
- **مکانیزم:** قرارداد مستقیم بر روی variance: طرفین اختلاف variance محقق‌شده با strike را تسویه می‌کنند. dispersion: شورت option index + لانگ سبد option اجزا (سود از «انحراف اجزا از هم»).
- **منابع:** [FasterCapital — Deep Dive](https://fastercapital.com/content/Volatility-Arbitrage--Volatility-Arbitrage--A-Deep-Dive-into-Swap-Strategies.html) — [Strike Money](https://www.strike.money/options/volatility-arbitrage)
- **نکات کلیدی:** مثال: variance swap با strike ۲۰٪، اگر realized ۲۵٪ شود، short side پرداخت می‌کند. این ابزارها عمدتاً OTC نهادی‌اند.
- **سختی رباتی:** بسیار سخت (دسترسی OTC).

### ۳۲) آربیتراژ ایجاد/فدای ETF (Creation-Redemption Arbitrage)
- **مکانیزم:** اگر ETF در secondary market **بالای NAV** باشد: AP سبد سهام را می‌خرد، به fund تحویل می‌دهد، واحد ایجاد می‌گیرد و می‌فروشد. اگر **زیر NAV** باشد: برعکس. این مکانیزم قیمت ETF را به NAV می‌چسباند.
- **منابع:** [VettaFi](https://www.vettafi.com/insights/indexing-article-a-closer-look-at-authorized-participants-in-the-etf-ecosystem) — [Infrastructure Capital](https://infrastructurecapital.substack.com/p/the-creation-redemption-mechanism)
- **نکات کلیدی:** این کار مخصوص **Authorized Participants** (بروکرهای بزرگ) است؛ خُرد نمی‌تواند مستقیم AP باشد. ETFهای **ناتمام‌شده (non-transparent)** اسپرد وسیع‌تر و فرصت‌های آرب بیشتری دارند.
- **سختی رباتی:** (برای نهاد) متوسط؛ برای خُرد فقط به‌عنوان سیگنال (اسپرد ETF-NAV).

### ۳۳) آربیتراژ ETF اوراق (Bond ETF Arbitrage)
- **مکانیزم:** مثل #۳۲ ولی با ویژگی خاص: سبد ایجاد/فدا با holdings واقعی fund **هم‌پوشانی کامل ندارد** → آرب‌کار باید خودش اوراق را در secondary market پیدا کند؛ همین، اسپرد پایداری می‌سازد.
- **منبع:** [SUERF Policy Note](https://www.suerf.org/publications/suerf-policy-notes-and-briefs/the-anatomy-of-bond-etf-arbitrage/)
- **نکات کلیدی:** تعداد APها و تنوع inventory آن‌ها مستقیماً روی کارآمدی بازار اوراق اثر دارد (paper رسمی).
- **سختی رباتی:** بسیار سخت (بازی نهادی).

### ۳۴) جفت‌تریدینگ / آربیتراژ آماری (Pairs Trading / StatArb)
- **مکانیزم:** یافتن دو دارایی **co-integrated** (نه صرفاً correlated)؛ اسپرد آن‌ها mean-revert است. سیگنال z-score: اسپرد > +2σ → شورت اسپرد؛ < −2σ → لانگ.
- **منابع:** [M. Brenndoerfer — راهنمای تعاملی](https://mbrenndoerfer.com/writing/mean-reversion-statistical-arbitrage-pairs-trading) — [MathWorks](https://www.mathworks.com/discovery/statistical-arbitrage.html)
- **نکات کلیدی:** تمایز مهم: **correlation ≠ cointegration** (دو سری هم‌گام می‌توانند تا ابد از هم فاصله بگیرند). ریسک اصلی: **شکست دائمی رابطه** (regime risk) → ضررهای تجمعی؛ باید stop-loss و مانیتور همبستگی داشته باشید.
- **سختی رباتی:** کم تا متوسط — **بسیار مناسب برای شروع با فریمورک‌های آماده** (freqtrade، backtrader).

### ۳۵) استاتارب چندسختی (Multi-Asset StatArb / VECM)
- **مکانیزم:** تعمیم pairs به N دارایی با Johansen test و مدل VECM → K رابطه‌ی cointegration → هر ترکیب خطی در آن زیرفضا، اسپردی mean-revert است؛ سبد بزرگ از بت‌های ریز با neutral کردن فاکتورها (PCA).
- **منبع:** [Portfolio Optimization Book — بخش ۱۵.۷](https://portfoliooptimizationbook.com/book/15.7-statarb.html)
- **نکات کلیدی:** مثال کتاب: سه ETFِ S&P (SPY/IVV/VOO) با دو رابطه cointegration. در مقیاس، همین روی صدها نماد = سبد statarb کلاسیک (RenTech, DES).
- **سختی رباتی:** متوسط (ماتریسی‌ست؛ پیاده‌سازی VECM در Python با statsmodels آماده است).

### ۳۶) آربیتراژ بازآرایی شاخص‌ها (Index Reconstitution Arbitrage)
- **مکانیزم:** صندوق‌های passive برای صفر کردن tracking error، **در لحظۀ آخر** (روز قبل از effective date) جابه‌جایی می‌کنند → جریان قابل‌پیش‌بینی → خرید/شورت نمادهای ورودی/خروجی در تاریخ‌های درست.
- **منابع:** [مقالات NTU — مطالعۀ ۵۶ بازار](https://www.ntu.edu.sg/business/news-events/news/story-detail/timing-is-everything-how-index-investors-create-arbitrage-opportunities) — [EBC](https://www.ebc.com/forex/forex-how-index-rebalancing-pushes-stocks-lower) — [Candriam — Hedge Fund Journal](https://thehedgefundjournal.com/candriam-index-arbitrage-absolute-return-equity-market-neutral/)
- **نکات کلیدی:** مطالعۀ NTU (۲۰۰۶–۲۰۳، ۳۰۰+ افزودن/حذف): قیمت و حجم در روز قبل از reconstitution پیک می‌زند؛ سود آرب در **آسیا و بازارهای نوظهور بیشتر** از آمریکاست. کاهش weight-cap در شاخص Hang Seng ~۰.۶٪ return غیرعادی ایجاد کرد (فروشنی passive بدون خبر).
- **سختی رباتی:** کم تا متوسط (تقویم + مدل جریان؛ رقابت کم‌تر از HFT).

### ۳۷) همگرایی contango/backwardation (Futures Convergence)
- **مکانیزم:** قیمت فیوچرز به‌سمت اسپات در انقضا همگرا می‌شود. در contango: لانگ front + شورت back (سود از تخت‌شدن). در backwardation: شورت front + لانگ back.
- **منابع:** [DayTrading.com](https://www.daytrading.com/contango-backwardation-convergence-strategies) — [TradeAlgo](https://www.tradealgo.com/trading-guides/futures/contango-backwardation-futures-pricing)
- **نکات کلیدی:** **این آرب خالص نیست** — اگر curve تندتر شود ضرر می‌کنید. در مقابل cash-and-carry خالص (خرید اسپات + شورت فیوچرز و تحویل در expiry) ریسک‌سازتر است.
- **سختی رباتی:** متوسط (سیگنال شیب curve: Slope > +۲٪ = contango قوی؛ < −۱٪ = backwardation).

### ۳۸) کالندر اسپرد (Calendar Spread)
- **مکانیزم:** معامله شکل خود (نه جهت): اگر انتظار دارید contango تندتر شود → شورت ماه نزدیک + لانگ ماه دور؛ معکوس اگر backwardation شکل بگیرد.
- **منبع:** [TradeAlgo](https://www.tradealgo.com/trading-guides/futures/contango-backwardation-futures-pricing) (Strategy 1)
- **نکات کلیدی:** نسخه equity-index: fair value = funding − dividend؛ در حول ex-dividendها curve تخت می‌شود.
- **سختی رباتی:** متوسط.

### ۳۹) آربیتراژ ADR / دوگانه‌نماد (Dual-Listing Parity)
- **مکانیزم:** ADR و سهام زیربنایی (مثلاً H-shares در HK و NY) دارایی یکسان‌اند ولی در دو بازار با دو ارز معامله می‌شوند؛ هرگاه spread (بعد از تنظیم نرخ) از هزینه‌های ترید بیشتر شود: خرید ارزان‌قیمت‌تر + شورت/فروش گران‌تر.
- **منابع:** [مقاله — Law of One Price در China Dual-Listings](https://www.researchgate.net/publication/343847873_The_Law_of_One_Price_and_Arbitrage_on_China's_Dual-Listings_in_Hong_Kong_and_New_York) — [PDF مطالعاتی](https://files.core.ac.uk/download/pdf/303785945.pdf) — [BSIC — Dual Listings](https://bsic.it/dual-listings-the-rationale-behind-a-complicated-strategy/)
- **نکات کلیدی:** paper کلاسیک: بازده ماهانه ۰.۵–۳.۸٪ بعد از هزینه‌ها. paper دیگر (12 DLC, 1980–2002): تا ~۱۰٪ سالانه abnormal. محدودیت‌های واقعی: **ساعات ترید غیرهم‌پوشان، هزینه تبدیل ADR↔H-share، ریسک نرخ** (در HKD/USD کم است چون currency board).
- **سختی رباتی:** متوسط (داده + مدل اسپرد؛ سرعت کم‌حساس).

### ۴۰) آربیتراژ نرخ بهره پوششی (Covered Interest Arbitrage)
- **مکانیزم:** اگر بهره EUR > بهره USD و هزینه پوشش (forward) از اختلاف بهره کمتر باشد: وام USD → تبدیل به EUR → سرمایه‌گذاری EUR → forward برای قفل نرخ بازگشت → سود تضمین‌شده.
- **منابع:** [IG](https://www.ig.com/uk/trading-strategies/arbitrage-trading-in-forex-explained-190621) — [Investopedia](https://www.investopedia.com.cach3.com/terms/c/covered-interest-arbitrage.asp.html)
- **نکات کلیدی:** در بازارهای کارا تقریباً همیشه وجود ندارد (IP Parity برقرار است)؛ هرگاه swap point از مقادیر نظری عقب بماند، پنجره باز می‌شود. بازده هر ترید کم ولی حجم نهادی آن را بزرگ می‌کند.
- **سختی رباتی:** کم (محاسبه ساده) ولی در عمل دسترسی نهادی به forward لازم است.

### ۴۱) آربیتراژ مثلثی فارکس و بین‌بانکی (FX Triangular / Cross-Currency)
- **مکانیزم:** (الف) سه ارز در سه بانک/بروکر (یا سه pair در یک بروکر) وقتی حاصل‌ضرب نرخ‌ها از ۱ بگذرد؛ (ب) تفاوت کوئت همان pair در دو بروکر (two-currency).
- **منابع:** [IG](https://www.ig.com/en/trading-strategies/arbitrage-trading-in-forex-explained-190621.amp) — [IG UK](https://www.ig.com/uk/trading-strategies/arbitrage-trading-in-forex-explained-190621)
- **نکات کلیدی:** یک بروکر کارا این را نمی‌دهد؛ فضا در **اختلاف کوئت بین بروکرها** است. پنجره: میلی‌ثانیه. بروکرها botهای این سبک را با AI تشخیص می‌دهند و محدود می‌کنند.
- **سختی رباتی:** سخت + ریسک محدودشدن حساب.

### ۴۲) شکار دیویداند (Dividend Capture)
- **مکانیزم:** خرید سهم ۱–۳ روز قبل از ex-date + فروش بلافاصله بعد. دلیل وجودش: مطالعات تجربی نشان می‌دهند قیمت روز ex-date **کمتر از** مقدار دیویداند افت می‌کند (انومالی) → سود خُرد.
- **منابع:** [Il Matematico — تحلیل تجربی](https://ilmatematico.substack.com/p/dividend-capture-strategy-analysis) — [Fintel](https://fintel.io/dividend-capture-strategy)
- **نکات کلیدی:** پنجرۀ بهینه **بسیار باریک** حول ex-date؛ نگه‌داشتن طولانی‌تر سود را می‌خورد. نسخه پیشرفته (از ردایت): شورت یک correlated stock برای neutral کردن بازار + پوشش با covered call عمیق ITM.
- **سختی رباتی:** کم (تقویم دیویداند + اجرای مکانیکی) — رقابت کم‌تر از statarb.

### ۴۳) آربیتراژ Secondary پیش از IPO
- **مکانیزم:** سهام کارمندان در بازار secondary (tender offer/auction خصوصی) با تخفیف نسبت به قیمت IPO انتظار‌شده معامله می‌شوند؛ خرید در secondary + فروش بعد از opening (بعد از lockup).
- **منبع:** [KB Financial Advisors](https://kbfinancialadvisors.com/secondary-market-offering/)
- **نکات کلیدی:** secondary برعکس IPO، lockup ندارد؛ قیمت پیش‌تعریف‌شده ندارد (auction). ریسک: IPO ممکن است قیمت پایین‌تر از secondary باز شود یا لغو شود.
- **سختی رباتی:** (برای نهاد/VC) متوسط — بیشتر یک پلیر خصوصی است.

---

## E) انرژی و کالایی

### ۴۴) آربیتراژ فضایی برق (Spatial Arbitrage / FTR)
- **مکانیزم:** خرید برق در منطقۀ ارزان و فروش در منطقۀ گران (مبنا بر congestion شبکه). ابزار: **Financial Transmission Right (FTR)** — حق ارسال توان بین دو node که با اختلاف لوسیشنی قیمت تسویه می‌شود؛ خرید FTR ارزان و فروش آن وقتی ارزشش بالا می‌رود.
- **منابع:** [Diversegy](https://diversegy.com/energy-arbitrage-electricity-trading-strategies-risks/) — [NREL (PDF)](https://docs.nlr.gov/docs/fy14osti/61765.pdf)
- **نکات کلیدی:** ریسک: **خطای پیش‌بینی** (FTRs تاریخ انقضا دارند و carry نمی‌شوند) + ریسک physical در real-time.
- **سختی رباتی:** سخت (بازارهای RTO/ISO، نیاز به لایسنس و مدل بار/هواشناسی).

### ۴۵) آربیتراژ زمانی برق / Convergence Bidding
- **مکانیزم:** **Virtual trading**: خرید در بازار Day-Ahead + فروش در Real-Time (یا برعکس) تا اختلاف قیمت میانگین دو بازار صفر شود. بازیکن virtual نیاز به دارایی فیزیکی ندارد — خالص آربیتراژ بین‌زمانی.
- **منابع:** [NREL (PDF)](https://docs.nlr.gov/docs/fy14osti/61765.pdf) — [OSTI — Storage Arbitrage](https://www.osti.gov/servlets/purl/1358239)
- **نکات کلیدی:** مکانیزم convergence bidding در همه RTO/ISOهای آمریکا موجود است و عمداً طراحی شده تا premium در یک بازار را حذف کند.
- **سختی رباتی:** سخت.

### ۴۶) آربیتراژ با باتری / ذخیره‌سازی (Storage Arbitrage)
- **مکانیزم:** باتری در ساعات ارزان شارژ + ساعت گران دشارژ؛ بهینه‌سازی bid در DAM/RTM با سناریوی قیمت و state-of-charge.
- **منبع:** [OSTI — Energy Storage Arbitrage Under DAM/RTM](https://www.osti.gov/servlets/purl/1358239)
- **نکات کلیدی:** paper LP (linear programming) برای تخصیص بین دو بازار. در بازار کریپتو هم معادلش **آربیتراژ با لندینگ استیبل‌کوین** و staking است.
- **سختی رباتی:** سخت (فیزیکی) / متوسط (نسخه‌ی مالی آن).

---

## F) بازارهای پیش‌بینی و شرط‌بندی

### ۴۷) آربیتراژ آماری قرارداد هوا (Weather StatArb)
- **مکانیزم:** قرارداد Kalshi KXHIGH (دما در NYC/Chicago/Miami/LA/Denver) با مدل پیش‌بینی هوا (ensemble GFS ۳۱عضوی) price می‌شود؛ اگر بازار از مدل انحراف بدهد، ترید مدل‌محور.
- **منبع:** [TurbineFi — راهنمای botها](https://www.turbinefi.com/blog/prediction-market-arbitrage-bots-2026)
- **نکات کلیدی:** یک bot مستند GitHub با **۱۸۰ دلار سود** در این بازارها. **کم‌رقابت‌ترین** بخش بازارهای پیش‌بینی (نسبت به BTC hourly).
- **سختی رباتی:** **کم تا متوسط — یکی از بهترین ورودی‌ها برای رباتساز** (Kalshi API رایگان و بدون کارمزد معاملاتی).

### ۴۸) Surebets شرط‌بندی ورزشی (Sports Arbitrage)
- **مکانیزم:** پوشش تمام نتایج یک رویداد در bookmakerهای مختلف وقتی مجموع implied probability < ۱۰۰٪ باشد؛ staking بهینه با **کرایتریای Kelly**.
- **منابع:** [Botify — واقعیت botها](https://botify.vip/en/blog/sports-betting-bots-vs-music-botting/) — [PerformanceOdds](https://www.performanceodds.com/strategies/surebets-arbitrage-betting-complete-strategy-tools-calculators-real-examples-for-2025-2026/) — [GitHub — SureBetsBot](https://github.com/TessaRichardson/SureBetsBot)
- **نکات کلیدی:** حاشیه ۱–۵٪ (معمولاً ~۲٪)، فرصت چند دقیقه‌ای، سرمایه حداقل ~۱۰۰۰ یورو در چند book. **تله اصلی: bookmaker حساب برنده‌ها را می‌بندد (gubbing)** → مدل self-destructive. قانون طلایی: هر دو طرف نباید روی یک bookmaker باشد.
- **سختی رباتی:** کم (کد ساده) ولی **مدل کسب‌وکارش شکننده است**.

### ۴۹) آربیتراژ بین‌پلتفرمی بازارهای پیش‌بینی (Kalshi ↔ Polymarket)
- **مکانیزم:** یک رویداد باینری در هر دو پلتفرم؛ خرید Yes در ارزان‌تر + No در گران‌تر (مثلاً 0.45$ + 0.52$ = 0.97$ → تضمین 1$). اسپرد پیش از هزینه: ۱.۵–۴.۵٪؛ پنجره: ۲–۷ ثانیه.
- **منابع:** [ClawArbs](https://clawarbs.com/blog/kalshi-vs-polymarket-arbitrage/) — [NYC Servers](https://newyorkcityservers.com/blog/prediction-market-arbitrage-guide) — [TurbineFi](https://www.turbinefi.com/blog/prediction-market-arbitrage-bots-2026)
- **نکات کلیدی:** **هزینه‌ها معادله را می‌شکنند**: ~۲٪ Polymarket + تا ۳٪ Kalshi = ~۵٪ drag → اسپرد زیر ~۶٪ مرده است. قراردادها همیشه یکسان نیستند (مثلاً strikeهای متفاوت BTC hourly) → باید تطبیق‌دهی دقیق ticker انجام شود. سرمایه باید از قبل در هر دو پلتفرم باشد (انتقال بین آن‌ها وسط ترید ممکن نیست).
- **سختی رباتی:** **کم تا متوسط — بسیار مناسب** (APIهای رسمی رایگان، WebSocket، پنجره‌ها هنوز چند ثانیه‌ای‌اند).

### ۵۰) آربیتراژ داخل‌پلتفرمی بازار پیش‌بینی (YES+NO < $1)
- **مekanیزm:** در یک بازار باینری، اگر sum قیمت Yes و No در order book هر دو پلتفرم از ۱ دلار کمتر (یا sum سبد یک رویداد چندگانه از مجموع قیمت‌ها کمتر) باشد، خرید هر دو طرف = سود تضمینی در تسویه.
- **منابع:** [ClawArbs](https://clawarbs.com/blog/kalshi-vs-polymarket-arbitrage/) (بخش edge calculation) — [NYC Servers](https://newyorkcityservers.com/blog/prediction-market-arbitrage-guide)
- **نکات کلیدی:** ساده‌ترین شکل آرب بازار پیش‌بینی؛ عمق book در بازارهای نیچ کم است → slippage واقعیت را از قیمت نمایشی دور می‌کند.
- **سختی رباتی:** **کم** — مناسب‌ترین نقطۀ شروع کل لیست.

---

## Bonus) آربیتراژ دنیای واقعی (e-commerce و لایف‌استایل)

### B1) ریتهیل/آنلاین آربیتراژ Amazon (Retail & Online Arbitrage)
- **مکانیزم:** اسکن بارکد تخفیف‌های فروشگاه‌های فیزیکی/آنلاین؛ اگر سود خالص بعد از feeهای Amazon ≥ ۲۰٪ باشد → خرید + فروش از طریق FBA. ابزارها: Amazon Seller App، Keepa (تاریخچه قیمت)، Scoutify.
- **منابع:** [YourSellingGuide](https://yoursellingguide.com/2025/03/06/what-to-scan-for-retail-arbitrage/) — [Trivium](https://triviumco.com/blog/amazon-retail-arbitrage/) — [AIInfluencer](https://ainfluencer.com/amazon-retail-arbitrage/)
- **نکات کلیدی:** ماه طلایی: **ژانویه** (تخفیف‌های کریسمس ۷۵–۹۰٪ که ۱۰ ماه بعد دوباره می‌فروشند). مقیاس‌پذیری با استخدام «اسکنر» و آنلاین آرب.

### B2) دامن‌فلیپینگ (Domain Flipping)
- **مکانیزم:** خرید دامن‌های expired/ارزان با پتانسیل برندینگ + .com + کلمۀ کلیدی، و فروش بعد ماه‌ها/سال‌ها (بازده‌های گزارش‌شده ۲۰–۵۰x). ارزش‌گذاری با NameBio (فروش‌های مشابه) + backlink (Ahrefs).
- **منابع:** [Name Experts](https://nameexperts.com/blog/domain-flipping/) — [Bluehost](https://www.bluehost.com/blog/how-to-flip-domains/) — [Sandy Terrace](https://sandyterrace.com/how-to-domain-flipping-make-money-online/)
- **نکات کلیدی:** ۶۰–۸۰٪ سود را به سبد بهتری بازسرمایه‌گذاری کنید؛ ارزش‌افزودۀ landing page قیمت را بالا می‌برد.

### B3) وب‌سایت‌فلیپینگ (Website Flipping)
- **مکانیزم:** خرید سایت کم‌بهای «با استخوان‌بندی خوب» (ترافیک بالا، monetization ضعیف) در Flippa/Empire Flippers → بهبود SEO/محتوا/درآمد → فروش. خُردها با ۲۴–۳۶x ماهی درآمد می‌خرند و ۶–۱۲ ماه بعد با ۳–۵x فروش می‌کنند.
- **منابع:** [Website Closers](https://www.websiteclosers.com/resources/how-to-successfully-navigate-website-flipping/) — [LawWithMiller](https://lawwithmiller.com/blogs/milleriplaw/buy-boost-sell-the-ultimate-guide-to-website-flipping) — [BuyingOnlineBusinesses](https://buyingonlinebusinesses.com/category/learn-flipping-websites/)

### B4) آربیتراژ امتیاز سفر / miles (Points Arbitrage)
- **مکانیزم:** (الف) خرید miles در discount sale؛ (ب) transfer پُنت‌های flexible کارت به پروگرم ایرلاین با transfer bonus؛ (ج) ترکیب هر دو: transfer 80k + خرید 30k در sale = رده business با ۷۰–۸۰٪ تخفیف.
- **منبع:** [Biirdee](https://biirdee.com/blog/buy-or-transfer-points-the-ultimate-guide-to-points-arbitrage)

### B5) چرنینگ کارت اعتباری (Credit Card Churning)
- **مکانیزم:** دریافت sign-up bonus کارت‌های پرمنفعت → تأمین minimum spend (طبیعی، نه manufactured) → close/product-change قبل از انقضای fee سالانه → تکرار. مثال: 75k پُنت Amex = 750$ در پورتال یا ~1800$ با transfer به Aeroplan.
- **منابع:** [Due](https://due.com/a-step-by-step-guide-to-credit-card-churning/) — [CardClassroom](https://cardclassroom.com/blog/credit-card-churning-risks-and-rewards)
- **نکات کلیدی:** ریسک‌ها: credit score، کارمزد سالانه، بار اداری؛ فاصله ۲–۳ ماه بین اپلیکیشن‌ها.

---

## جدول مقایسۀ سریع (برای انتخاب ربات hanzobot)

| استراتژی | سرمایه اولیه | حساسیت سرعت | رقابت | ریسک اصلی | امتیاز شروع |
|---|---|---|---|---|---|
| ۵۰/۴۹/۴۷. بازار پیش‌بینی (Kalshi API) | کم | کم (ثانیه) | **کم** | fee/slippage | ★★★★★ |
| ۳/۴. فیوندینگ/بیس‌ترید | متوسط | کم | متوسط | برگشت funding، لیکوئید | ★★★★★ |
| ۳۴. Pairs/StatArb | کم | کم | متوسط | شکست رابطه | ★★★★☆ |
| ۱. Cross-Exchange | زیاد (پیش‌فاند چند صرافی) | بالا (ms) | بسیار زیاد | ترانسفر/لیکوئید | ★★★☆☆ |
| ۱۴. Whale copy-trade | کم | بالا | زیاد | exit liquidity | ★★★☆☆ |
| ۲۵. فارمینگ اپتایمایزر | کم | کم | کم | IL + قرارداد هوشمند | ★★★★☆ |
| ۱۹. لیکوئیدیشن bot | کم (فلش‌لن) | **بسیار بالا** | بسیار زیاد | gas war | ★★☆☆☆ |
| ۱۵/۱۶. MEV آرب | کم (gas) | **بسیار بالا** | بسیار زیاد | revert/تقلب | ★★☆☆☆ |
| ۱۰/۱۱. Latency/News HFT | **بسیار زیاد** (colo) | میکروثانیه | انحصاری | زیرساخت | ★☆☆☆☆ |
| ۶. P2P | کم | کم | کم (بومی) | counterparty/بانک | ★★★★☆ (بسته به بازار) |

## توصیه‌ها برای hanzobot

1. **نقطۀ شروع پیشنهادی (کم‌ریسک‌ترین با بیشترین یادگیری):** Bot بازار پیش‌بینی (مقاله ۵۰ ← ۴۹ ← ۷). Kalshi API رایگان و مستند است، پنجره‌ها هنوز باز‌اند، و معماری «اسکن اسپرد → تطبیق → اجرای دوپا → محاسبه edge بعد از fee» دقیقاً همان الگویی است که بعداً به cross-exchange arb یا funding arb تعمیم می‌دهید.
2. **بهترین بازده پایدار برای سرمایه متوسط:** فیوندینگ/بیس‌ترید (۳/۴) — ربات ساده‌ای که هر ۸ ساعت funding را اسکن کند، بهترین آستانه APY را بدهد و پوزیشن‌ها را re-balance کند.
3. **بازارهای بومی:** اگر در بازار ایران/خاورمیانه کار می‌کنید، P2P (۶) اسپردهای بزرگ‌تری دارد ولی ریسک counterparty را جدی بگیرید.
4. **از آن‌ها دوری کنید (برای شروع):** MEV سندیویچ (۱۷) [اخلاق/قانون + رقابت انحصاری]، Latency HFT (۱۰/۱۱) [نهادی]، Sybil ایردراپ (۲۴) [ریسک حقوقی].
5. **اصل طلایی همه آرب‌ها (از منابع مکرر):** edge = اسپرد − (کارمزد + اسلیپیج + هزینه فرصت/انتقال). هر استراتژی که edge بعد از هزینه منفی شود، حتی «ریسک‌ساز» هم بودنش را از دست می‌دهد.

## یادداشت‌های حقوقی و اخلاقی
- سندیویچ/فرانت‌رانینگ (۱۷/۱۸) در بسیاری حوزه‌ها **در معرض ریسک قانونی** و حتماً ناپسند جامعه است؛ دفاع (Flashbots Protect/CoW/slippage تنگ) را یاد بگیرید.
- Sybil ایردراپ (۲۴) در بیشتر پروتکل‌ها نقض صریح ToS است.
- Surebet (۴۸) قانونی است ولی bookmakerها برنده‌ها را limit می‌کنند.
- هرگز با سرمایه‌ای که تحمل از دست دادنش را ندارید آرب نکنید — «ریسک‌ساز» در آربیتراژ همیشه با ریسک‌های عملیاتی (exchange freeze, flashloan revert, oracle delay, گب‌بک P2P) همراه است.
