# Deep Dive: بازارهای پیش‌بینی — از منابع معتبر (رسمی + آکادمیک)

> جمع‌بندی مطالعه مستندات رسمی Kalshi و Polymarket، جدول‌های رسمی کارمزد، و داده‌های
> واقعی API — سپتامبر ۲۲۶. همه‌ی اعداد زیر از منابع یکسره (اول‌دست) گرفته‌اند.

---

## ۱) کارمزد رسمی — کالیبراسیون مدل ما

### Kalshi (منبع: جدول رسمی کارمزد، مؤثر از ۲۰-۰۷-۰۷)
- **Taker:** `fees = round_up(M × 0.07 × C × P × (1−P))`
- **Maker:** `fees = round_up(M × 0.0175 × C × P × (1−P))` → **یک‌چهارم کارمزد تیکر!**
- `M` = ضریب قرارداد (پیش‌فرض ۱)؛ `C` = تعداد؛ `P` = قیمت دلاری
- رُندینگ: به سمت بالا تا **سنتی‌سنت** روی مجموع (fee + positionCost)
  (ساده‌سازی ما در `models.py` کمی محافظه‌کارانه‌تر است — یعنی edge را کمی کم‌تر نشان می‌دهد؛ بی‌خطر)
- ⚠️ **برخی سری‌ها (series) کارمزد غیراستاندارد دارند** — قبل از live باید کارمزد سریِ هر بازار را جداگانه چک کرد
- کارمزد special event برای maker: ۰.۲۵٪ ثابت از premium

### Polymarket (منبع: مستندات رسمی docs.polymarket.com/trading/fees)
- فرمول: `fee = C × feeRate × p × (1−p)` — **فقط تیکر می‌پردازد؛ maker هرگز کارمزد ندارد**
- نرخ تیکر به‌ازای هر دسته:

| دسته | Taker | Rebate برای Maker |
|---|---|---|
| Crypto | 0.07 | 20٪ |
| Sports | 0.05 | 15٪ |
| Finance / Politics / Tech / Mentions | 0.04 | ۲۵٪ |
| Economics / Culture / Weather / Other | 0.05 | 25٪ |
| **Geopolitics / world events** | **0 (رایگان!)** | — |

- دقت کارمزد: ۵ رقم اعشار؛ منحنی متقارن حول ۵۰٪ (پیک در 50¢ = 1.75$ برای ۱۰ قرارداد در Crypto)
- برنامه‌ی **Taker Rebate** پلکانی هم وجود دارد (بازگشت بخشی از کارمزد به تیکرهای پرتور)

### چه چیزی برای استراتژی‌مان تغییر کرد
1. **Maker-first = بزرگ‌ترین اهرم هزینه:** روی Kalshi، اجرای maker کارمزد را ÷۴ می‌کند؛ روی Polymarket صفر می‌کند و rebate هم می‌دهد. → «حالت maker» (post-only، مثل poly-maker) بالاترین اولویت roadmap است.
2. **هزینه اسپرد، تابع قیمت است:** همان gross spread 5¢، در قیمت 50/50 تقریباً 1.75٪ (Kalshi taker) هزینه دارد ولی در 95/5 زیر 0.2٪. → آستانه‌ی `min_net_edge` بعداً باید price-dependent شود.
3. **ناهمتجانسی cross-venue:** پای Polymarket در بازارهای ژئوپلیتیک **رایگان** است — یعنی در آرب Kalshi↔Polymarket برای آن دسته، فقط کارمزد Kalshi (0.07 یا 0.0175) را می‌دهیم.
4. **کلاس آربیتراژ جدید: Σ-arb چندنتیجه‌ای** — در رویدادهای چندنتیجه‌ای، اگر مجموع `yes_ask` همه‌ی نتایج < 1 (بعد از کارمزد) باشد، خرید همه = تضمین 1$. در Polymarket با مکانیزم **negative risk** ارزان است (1 NO = 1 YES در همه‌ی نتایج دیگر) — جزئیات در بخش ۳.

---

## ۲) Microstructure واقعی Kalshi (از داده‌ی زنده‌ی API v2)

- **مهاجرت v2:** base قدیمی `trading-api.kalshi.com` فقط redirect می‌دهد؛ API جدید:
  `https://api.elections.kalshi.com/trade-api/v2` (داده‌ی بازار بدون کلید)
- **ساختار tick متغیر:** هر بازار `price_level_structure` + `price_ranges` دارد؛
  در داده‌ی واقعی: `center_deci_edge_centi_cent` = 1bp در لبه‌ها (0–1٪ و 99–100٪)، 1¢ در میانه.
  → بک‌تست‌های واقعی باید tick واقعی هر بازار را مدل کنند (نه tick ثابت)
- **MVE (Multivariate Events):** بازارهای ترکیبی چندپایه با `mve_collection_ticker` +
  `mve_selected_legs` — محصول جدیدی که باید از آرب باینری جدا (و بعداً به‌عنوان کلاس مستقل) مدل شود
- **`can_close_early: true` + `settlement_timer_seconds: 5`:** بازار ممکن است **قبل از close_time بسته شود** → ریسک: تسویه زودتر از انتظار؛ در پورتفولیو ما تسویه بر اساس `result` است نه زمان، پس امن است
- **pagination با `cursor`** + rate-limit 429 (کلاینت ما backoff دارد)

---

## ۳) مکانیزم‌های Polymarket (از مستندات رسمی)

### توکن‌ها و CTF
- هر نتیجه، یک ERC-1155 است؛ **split/merge**: pUSD ⇄ (1 YES + 1 NO). merge یعنی تبدیل هر جفت کامل به $1 — همان «قفل‌شدن edge» در زبان Polymarket
- **Negative Risk:** در رویداد چندنتیجه‌ای، 1 NO هر نتیجه ⇄ 1 YES **همه‌ی نتایج دیگر** (تبدیل اتمیک از طریق Neg Risk Adapter — [کنترکت](https://github.com/Polymarket/neg-risk-ctf-adapter))
  - ⚠️ Augmented neg risk: placeholderها را نخرید (تا نام‌گذاری نشود)؛ «Other» تعریف متغیری دارد
- **Order types:** GTC/GTD برای کوئت passive (+ **post-only**)، FOK/FAK برای اجرای آنی؛ سفارش‌ها قابل edit نیستند → cancel + replace؛ ارسال **batch** برای کاهش latency
- **داده‌ی realtime:** WebSocket market stream (مستندات صریح: «به‌جای polling subscribe کنید»)

### راهنمای رسمی Market-Making (مستندات Polymarket) — چک‌لیست ما
- کوئت دوطرفه حول fair value + **skew بر اساس موجودی**
- **لغو فوری کوئت‌های stale** وقتی شرایط بازار عوض شود
- **GTD برای رویدادها**: قبل از کاتالیزور شناخته‌شده کوئت‌ها منقضی شوند
- **Price guards**: رد قیمت‌های خارج از محدوده‌ی منطقی نسبت به میانه
- **Kill switch**: لغو همه‌ی سفارش‌ها در خطا یا عبور از سقف
- مانیتورینگ fills با order-updates realtime
- پس از reconnect: اول open orders + تریدهای اخیر را sync کنید

---

## ۴) فهرست منابع معتبر (برای ارجاع و مطالعه‌ی بیشتر)

### رسمی / یکسره
- Kalshi — Fee Schedule (PDF رسمی، مؤثر ۲۰-۰۷-۷): <https://kalshi.com/docs/kalshi-fee-schedule.pdf>
- Kalshi — API v2: <https://api.elections.kalshi.com> (داده‌ی بازار عمومی)
- Polymarket — Fees: <https://docs.polymarket.com/trading/fees>
- Polymarket — Market Making: <https://docs.polymarket.com/trading/market-making>
- Polymarket — Negative Risk: <https://docs.polymarket.com/concepts/negative-risk>
- Polymarket — Prices & Orderbook: <https://docs.polymarket.com/concepts/prices-orderbook>
- Polymarket — Resolution: <https://docs.polymarket.com/concepts/resolution>
- Polymarket — Real-Time Data: <https://docs.polymarket.com/market-data/realtime-data>
- Polymarket — Maker/Taker Rebates: <https://docs.polymarket.com/programs/maker-rebates> ، <https://docs.polymarket.com/programs/taker-rebates>
- Neg Risk Adapter (کنترکت مرجع‌شده توسط Polymarket): <https://github.com/Polymarket/neg-risk-ctf-adapter>

### ابزار/جامعه (مستند و عمومی)
- Awesome Prediction Market Tools (دایرکتوری جامع): <https://github.com/aarora4/Awesome-Prediction-Market-Tools>
- poly-maker (الگوی MM ایمن): <https://github.com/warproxxx/poly-maker>
- KalshiMarketMaker (الگوی A-S): <https://github.com/rodlaf/KalshiMarketMaker>
- Oddpool (داده‌ی cross-venue برای تحقیق): <https://www.oddpool.com>

### مطالعاتی (داده‌ی بازار)
- MEV/تمرکز بازار: گزارش‌های EigenPhi (مستندشده در تحقیق اول)
- داده‌ی تاریخی order book برای بک‌تست: Polymarket Data Resources + Kalshi marketdata endpoint (نقشه‌ی جمع‌آوری: `data/snapshots`)

---

## ۵) نقشه‌ی داده (Data Plan)

- **snapshot schema v1:** `{schema, ts, source, endpoint, markets[raw], opportunities[]}` در
  `kalshi-arb/data/snapshots/YYYYMMDDTHHMMSSZ.json`
- **اجرا:** `python -m kalshi_arb.bot --loop --interval 15 --collect` (هر ۱۵ ثانیه یک snapshot)
- **تحلیل:** `python -m kalshi_arb.bot --analyze data/snapshots`
  → تعداد بازارها، نرخ ظهور فرصت، توزیع edge (min/p50/p90/max)، تیکرهای پرتکرار، سری زمانی
- **حافظه:** auto-prune به `snapshot_keep` (پیش‌فرض ۲۰۰ فایل)
- **هدف تحلیل اول:** «در هر N دقیقه، چند market قابل‌آربیتراژ واقعی (net edge > 0 بعد از کارمزد) ظاهر می‌شود و edge میانگینش چقدر است؟» — این عدد، تصمیم go/no-go برای فاز live است

---

## ۶) طراحی حالت Maker و Cross-Venue (پیاده‌سازی‌شده در MVP)

### ۶.۱) Maker Combo (`MAKER_COMBO`)
وقتی `yes_bid + no_bid < 1 − fee_maker − ε` باشد، به‌جای کراس کردن اسپرد، در **نوبیت‌ها**
استراحت می‌کنیم. با جدول رسمی Kalshi (مؤثر ۲۰۲۶-۰۷-۲۰)، کارمزد maker دقیقاً یک‌چهارم
taker است (`0.0175×C×P×(1−P)` با گرد به بالا سنتیکنت) و در Polymarket maker اصلاً
کارمزد ندارد + rebate ۱۵–۲۵٪. یعنی edge نازک‌تری (پیش‌فرض ≥ 0.2c خالص) با هزینه‌ی
بسیار کم قابل‌اجرا می‌شود — دقیقاً همان منطق راهنمای رسمی market-making Polymarket.

**شبیه‌سازی fill در paper:** چون در paper order واقعی نمی‌گذاریم، قاعده‌ی پر شدن:
شرط باید `maker_fill_scans` اسکن پشت‌سرهم (پیش‌فرض ۳ ≈ ۴۵ ثانیه در loop ۱۵s) برقرار
بماند؛ شمارنده در `state/paper_state.json` (کلید `extra.maker_warm`) ذخیره و با
ری‌استارت حفظ می‌شود. اگر شرط یک اسکن هم از بین برود، شمارنده صفر می‌شود.

**چرا این approximation؟ ریسک‌های واقعی maker (از تحقیق):**
- **Partial fill / adverse selection:** در live فقط وقتی نوبیت ما پر می‌شود که طرف مقابل
  بداند قیمت ما «بد» است (مثلاً خبر آمد) — fillmaker واقعی باید leg دوم را همان لحظه
  پوشش دهد (leg hedging). در paper ما فرض می‌کنیم هر دو پا هم‌زمان پر می‌شوند.
- **رقابت با makerهای دیگر** و عمق محدود نوبیت‌ها (فیلتر `bid_size ≥ 1` فقط حداقل
  عمق را چک می‌کند).
- **Risks of maker on Polymarket:** راهنمای رسمی هشدار می‌دهد که post-only بودن +
  price guards + kill switch الزامی است؛ ما فعلاً فقط در paper و فقط روی Kalshi شبیه‌سازی می‌کنیم.

### ۶.۲) Cross-Venue (Kalshi ↔ Polymarket)
دو صحنه برای رویداد یکسان معمولاً price متفاوت می‌دهند (audience و capital متفاوت):
- `XV_A`: YES را در Kalshi (ask) بخر + NO را در Polymarket (1 − best_bid)
- `XV_B`: NO را در Kalshi (ask) بخر + YES را در Polymarket (ask)
- هزینه = کارمزد taker Kalshi (`0.07×K×(1−K)`) + کارمزد taker Polymarket
  (`rate×P×(1−P)`)؛ rate هر جفت در venue-map (پیش‌فرض 0.05 = sports).
- آستانه‌ی خالص بالاتر از single-venue (`xv_min_net_edge` = 0.01 ≈ 1c) چون دو صحنه،
  دو fill و ریسک تسویه.
- **سقف ۳ پوزیشن هم‌زمانی cross** و حداقل عمق/نقدشوندگی PM (liquidity ≥ $5k، vol24h ≥ $1k).

**تطبیق بازارها — فقط انسانی:** هرگز بین بازارهای دو صحنه auto-match اجرا نمی‌شود.
ابزار `--suggest-matches` کاندیداها را با امتیاز (overlap تکیه‌واژه‌ها + نزدیکی تاریخ
پایان) می‌چیند تا **دست** بررسی شوند؛ فقط جفت‌های تأییدشده در `config/venue_map.json`
(label + kalshi_ticker + pm_condition_id + pm_fee_rate) اجرا می‌شوند.

**ریسک اصلی cross-venue: تفاوت قاعدۀ تسویه.** فرض ما: هر دو صحنه روی *همان رویداد
با همان منبع/معیار* بسته می‌شوند و تسویه paper از نتیجه‌ی Kalshi انجام می‌شود. اگر
قاعده‌ی رسمی یکی از صحنه‌ها حتی جزئی متفاوت باشد (مثلاً «بسته شدن بالای ۴۰۰» با
منبع قیمت متفاوت)، ممکن است هر دو پا ببازیم — به‌همین دلیل:
- حتماً **قاعده‌ی رسمی هر دو بازار** را قبل از افزودن جفت بخوانید؛
- `--validate-pm` را در اولین اجرا روی VPS بزنید تا اسکیما Gamma هم تأیید شود؛
- اندازه‌ی استیک cross روی همان سقف‌های ریسک single-venue (≤ $25، ≤ 5٪ bankroll).

**محدودیت‌های فعلی (paper):** اجرای پای PM فرض fill در best quote است (بدون مدل
عمق order book PM)؛ اندازه‌ی جفت با `ask_size` پای Kalshi محدود می‌شود؛ WebSocket
هر دو صحنه هنوز ندارد (polling) — بنابراین پنجره‌ی کشف آرب cross-venue در paper
بزرگ‌نمایی می‌شود و نتایج live می‌تواند بدتر باشد.
