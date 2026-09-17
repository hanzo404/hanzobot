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
- تشخیص دو شکل: `COMBO_BUY` (قابل اجرا در paper) و `COMBO_SELL` (فقط سیگنال)
- مدل کارمزد Kalshi: `fee ≈ 0.07 × C × P × (1−P)` با round-up به سنت (ضریب پیکربندی‌پذیر —
  قبل از live با مستندات جاری چک شود)
- Paper portfolio با ذخیره‌سازی JSON (bankroll، پوزیشن‌ها، تسویه خودکار در بسته‌شدن بازار)
- مدیریت ریسک: سقف استیک هر فرصت، سقف پوزیشن‌های باز، **حداکثر یک پوزیشن در هر بازار**،
  و **kill switch** زیان روزانه (خاموشی تا روز بعد UTC)
- اعلان: console همیشه + Telegram اختیاری (بدون کلید هم کار می‌کند)
- بدون هیچ وابستگی خارجی — فقط stdlib پایتون (3.10+)

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
| `telegram_token` / `telegram_chat_id` | "" | اعلان تلگرام (اختیاری) |

## تست‌ها
```bash
python3 -m unittest discover -s tests -v   # 30 تست، آفلاین
```

## وضعیت و نقشۀ راه
- ✅ MVP paper-trading (این ریلیز)
- ⬜ اتصال cross-venue (Kalshi ↔ Polymarket) — با تطبیق دقیق تیکر و هزینه‌ی هر دو طرف
- ⬜ WebSocket به‌جای polling برای کاهش پنجره‌ی کشف
- ⬜ اجرای live فقط با کلید API و در اندازه‌ی کوچک، پس از ≥ ۲ هفته paper با نتایج مثبت
- ⬜ بک‌تست روی داده‌ی تاریخی order book (با ذخیره‌سازی اسکن‌های زنده از همین MVP)

## هشدارها (از تحقیق)
- این یک ابزار **آموزشی/paper** است؛ هیچ سودی تضمین نشده.
- edge واقعی = اسپرد − (کارمزد + اسلیپیج + عمق) — اگر بعد از هزینه منفی بود، اجرا نشود.
- بازارهای multivariate (MVE) و بازارهای نزدیک انقضا به‌صورت خودکار حذف می‌شوند.
- قبل از هر live، جدول کارمزد جاری Kalshi را با مستندات رسمی مقایسه کنید.
