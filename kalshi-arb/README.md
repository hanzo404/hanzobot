# kalshi-arb — ربات آربیتراژ بازار پیش‌بینی Kalshi (Paper-Trading MVP)

نسخه‌ی MVP از استراتژی‌های منتخب تحقیق (فایل‌های `docs/` در ریشه‌ی ریپو):
آربیتراژ ساختاری داخل‌پلتفرم روی بازارهای باینری Kalshi — خرید هم‌زمان YES در قیمت ask
و NO در قیمت ask وقتی `yes_ask + no_ask < $1.00` باشد. چون دقیقاً یکی از دو پا در تسویه
$1.00 می‌دهد، **سود پس از اجرای هر دو پا قفل می‌شود** (بدون ریسک جهت) و ریسک اصلی
صرفاً هزینه‌ها و عمق order book است.

## چرا Kalshi؟ (مستند در `docs/`)
- کم‌رقابت‌ترین فزای زنده‌ی آربیتراژ (پنجره‌های ۲–۷ ثانیه‌ای هنوز بازند)
- API عمومی **رایگان و بدون کلید** برای داده‌ی بازار (v2: `api.elections.kalshi.com/trade-api/v2`)
- تحت نظارت CFTC با جدول کارمزد شفاف → محاسبات قابل‌استدلال
- الگوهای آماده‌ی آموزشی: `KalshiMarketMaker`، `poly-maker`، `Awesome-Prediction-Market-Tools`

## امکانات این MVP
- اسکن صفحه‌به‌صفحه‌ی بازارهای open + فیلترها (liquidity، volume 24h، نوع binary،
  حذف multivariate/MVE، فاصله تا انقضا)
- تشخیص سه شکل: `COMBO_BUY` (taker، قابل اجرا)، `MAKER_COMBO` (بیت‌های استراحتی،
  قابل اجرا با شبیه‌سازی fill) و `COMBO_SELL` (فقط سیگنال)
- **Cross-venue:** آربیتراژ Kalshi ↔ Polymarket روی جفت‌های **تصویب‌شده‌ی انسانی**
  (`config/venue_map.json`) — دو شکل `XV_A` و `XV_B` با کارمزد رسمی هر دو صحنه
- مدل کارمزد **طبق جدول رسمی Kalshi** (مؤثر ۲۰۲۶-۰۷-۲۰): taker `0.07×C×P×(1−P)`،
  maker `0.0175×C×P×(1−P)` (یک‌چهارم) — و جدول رسمی Polymarket: فقط taker،
  `rate×C×P×(1−P)` (sports 0.05، crypto 0.07، ...)
- **Data collection:** `--collect` هر اسکن را به‌عنوان snapshot در `data/snapshots/`
  ذخیره می‌کند + `--analyze DIR` آمار واقعی آربیتراژ را می‌دهد (توزیع edge، تیکرها، سری زمانی)
- Paper portfolio با ذخیره‌سازی JSON (bankroll، پوزیشن‌ها، تسویه خودکار در بسته‌شدن بازار)
- مدیریت ریسک: سقف استیک هر فرصت، سقف پوزیشن‌های باز، **حداکثر یک پوزیشن در هر بازار**،
  **سقف ۳ پوزیشن cross-venue**، و **kill switch** زیان روزانه (خاموشی تا روز بعد UTC)
- اعلان: console همیشه + Telegram اختیاری (بدون کلید هم کار می‌کند)
- بدون هیچ وابستگی خارجی — فقط stdlib پایتون (3.10+)

## حالت Maker (`MAKER_COMBO`)
وقتی `yes_bid + no_bid < $1` باشد، به‌جای کراس کردن اسپرد، در **نوبیت‌ها** استراحت می‌کنیم
(نقش maker): کارمزد Kalshi یک‌چهارم می‌شود. چون در paper ما order واقعی نمی‌گذاریم،
قاعده‌ی پرز filled شدن این است: شرط باید `KA_MAKER_FILL_SCANS` اسکن پشت‌سرهم (پیش‌فرض ۳)
برقرار بماند تا paper-fill ثبت شود؛ شمارنده در state ذخیره می‌شود و با ری‌استارت حفظ می‌ماند.
اگر شرط حتی یک اسکن از بین برود، شمارنده صفر می‌شود.

```bash
# دمو آفلاین: ۳ اسکن پشت‌سرهم -> پر شدن پوزیشن -> تسویه
python3 -m kalshi_arb.bot --once --fixture tests/fixtures/markets_maker.json
```
**ریسک‌های واقعی (مستند در deep-dive):** در live، maker یعنی partial fill و adverse
selection — بدون order واقعی و پوشش پای دیگر (leg hedging)، این حالت فقط در paper معنا دارد.

## Cross-Venue (Kalshi ↔ Polymarket)
فقط روی جفت‌هایی که **دستی** در `config/venue_map.json` ثبت کرده‌اید — هرگز به‌صورت
خودکار بین بازارهای دو صحنه تطبیق داده نمی‌شود (ریسک رویداد/تسویه‌ی متفاوت):

```json
{"pairs": [{"label": "ETH 4000 Sep18",
            "kalshi_ticker": "KXETHABOVE-26SEP18-4000",
            "pm_condition_id": "0xabc…",
            "pm_fee_rate": 0.05}]}
```

شکل‌ها: `XV_A` = YES در Kalshi (ask) + NO در Polymarket (1 − best_bid) و
`XV_B` = NO در Kalshi (ask) + YES در Polymarket (ask). تسویه بر اساس نتیجه‌ی
Kalshi انجام می‌شود (فرض: هر دو صحنه روی همان رویداد/منبع بسته می‌شوند —
**تفاوت در قاعدۀ تسویه، ریسک اصلی این استراتژی است**).

```bash
# اعتبارسنجی زنده‌ی API Polymarket (Gamma) — اولین بار حتماً اجرا کنید:
python3 -m kalshi_arb.bot --validate-pm

# پیشنهاد جفت‌های مشابه (فقط برای بررسی دستی — هرگز خودکار اجرا نمی‌شود):
python3 -m kalshi_arb.bot --suggest-matches 10

# اسکن cross-venue (آفلاین با فیکسچرها یا زنده):
python3 -m kalshi_arb.bot --cross --once --fixture tests/fixtures/markets_sample.json \
    --pm-fixture tests/fixtures/pm_sample.json
python3 -m kalshi_arb.bot --cross --loop --interval 30
```

## جمع‌آوری داده (برای بک‌تست بعدی)
```bash
# هر ۱۵ ثانیه یک snapshot از کل بازارهای open ذخیره می‌کند:
python3 -m kalshi_arb.bot --loop --interval 15 --collect

# گزارش: چقدر آربیتراژ واقعی در داده‌ی جمع‌شده هست؟
python3 -m kalshi_arb.bot --analyze data/snapshots
```
هدف: اندازه‌گیری «تعداد و توزیع net edge واقعی در هر N دقیقه» — مبنای تصمیم go/no-go برای live.

## اجرا

```bash
cd kalshi-arb

# یک اسکن با داده‌ی آفلاین (فیکسچر واقعی API) — بدون نیاز به اینترنت:
python3 -m kalshi_arb.bot --once --fixture tests/fixtures/markets_sample.json

# چرخۀ پیوسته روی API زنده (در محیطی که دسترسی به api.elections.kalshi.com دارد):
python3 -m kalshi_arb.bot --loop --interval 15

# وضعیت پورتفولیو / ریست:
python3 -m kalshi_arb.bot --report
python3 -m kalshi_arb.bot --reset
```

## پیکربندی
کپی `config.example.json` به `config.json` (یا متغیرهای محیطی با پیشوند `KA_`):

| کلید | پیش‌فرض | توضیح |
|---|---|---|
| `min_net_edge` | 0.005 | حداقل سود خالص هر جفت (دلار) بعد از کارمزد |
| `fee_rate` | 0.07 | ضریب کارمزد taker Kalshi |
| `max_stake_usd` | 25 | سقف استیک هر فرصت |
| `stake_pct_of_bankroll` | 0.05 | سقف استیک به‌عنوان درصد bankroll |
| `max_open_positions` | 5 | سقف پوزیشن‌های هم‌زمان |
| `daily_max_loss_usd` | 50 | آستانه‌ی kill switch |
| `min_liquidity_usd` / `min_volume_24h_usd` | 2000 / 500 | فیلتر بازارها |
| `maker_min_net_edge` | 0.002 | حداقل سود خالص حالت maker (edge نازک‌تر، کارمزد ¼) |
| `maker_fill_scans` | 3 | تعداد اسکن‌های پشت‌سرهم تا paper-fill maker |
| `pm_api_base` | gamma-api.polymarket.com | API عمومی Polymarket (بدون کلید) |
| `pm_min_liquidity_usd` / `pm_min_volume_24h_usd` | 5000 / 1000 | فیلتر بازارهای Polymarket |
| `pm_fee_rate_default` | 0.05 | ضریب کارمزد taker Polymarket (sports) — در venue-map قابل‌تغییر |
| `xv_min_net_edge` | 0.01 | حداقل سود خالص cross-venue |
| `max_cross_positions` | 3 | سقف هم‌زمانی پوزیشن‌های cross |
| `venue_map_file` | config/venue_map.json | جفت‌های تصویب‌شده‌ی cross-venue |
| `telegram_token` / `telegram_chat_id` | "" | اعلان تلگرام (اختیاری) |

## تست‌ها
```bash
python3 -m unittest discover -s tests -v   # 51 تست، آفلاین
```

## وضعیت و نقشۀ راه
- ✅ MVP paper-trading (این ریلیز)
- ✅ Data collection + analyzer + اولین snapshot واقعی (داده‌ی زنده‌ی API)
- ✅ کالیبراسیون کارمزد با جدول رسمی Kalshi + مستندات رسمی Polymarket (`docs/prediction-markets-deep-dive.md`)
- ✅ **حالت maker** — `MAKER_COMBO` با شبیه‌سازی fill (N اسکن پشت‌سرهم)؛ کارمزد ¼
- ✅ **Cross-venue** Kalshi ↔ Polymarket — روی جفت‌های تصویب‌شده‌ی انسانی + `--suggest-matches`
- ⬜ Order واقعی post-only در live (بنا بر آن‌گاه که fillmaker واقعی معنا پیدا کند)
- ⬜ WebSocket به‌جای polling برای کاهش پنجره‌ی کشف
- ⬜ آرب Σ چندنتیجه‌ای (مجموع yes_ask همه‌ی نتایج یک رویداد) — با neg-risk در Polymarket
- ⬜ چک کردن کارمزد غیراستاندارد سری‌های خاص پیش از live
- ⬜ بک‌تست روی داده‌ی تاریخی order book (با ذخیره‌سازی اسکن‌های زنده از همین MVP)
- ⬜ اجرای live فقط با کلید API و در اندازه‌ی کوچک، پس از ≥ ۲ هفته paper با نتایج مثبت

## هشدارها (از تحقیق)
- این یک ابزار **آموزشی/paper** است؛ هیچ سودی تضمین نشده.
- edge واقعی = اسپرد − (کارمزد + اسلیپیج + عمق) — اگر بعد از هزینه منفی بود، اجرا نشود.
- بازارهای multivariate (MVE) و بازارهای نزدیک انقضا به‌صورت خودکار حذف می‌شوند.
- قبل از هر live، جدول کارمزد جاری Kalshi را با مستندات رسمی مقایسه کنید.
