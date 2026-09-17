# hanzobot

ربات ترید/آربیتراژ با تحقیق مستند. هر مرحله روی شواهد و منابع واقعی بنا شده.

## ساختار

```
docs/
├── arbitrage-strategies-50.md                 # تحقیق ۱: ۵۰ استراتژی/سبک آربیتراژ + ۶۰ منبع
├── bots-landscape-and-github-learning.md      # تحقیق ۲: چشم‌انداز ربات‌های سودده + منابع آموزشی GitHub
└── prediction-markets-deep-dive.md            # تحقیق ۳: مستندات رسمی Kalshi/Polymarket + کارمزد رسمی
kalshi-arb/
├── (MVP) ربات آربیتراژ بازار پیش‌بینی Kalshi — paper trading، بدون وابستگی خارجی
└── data/snapshots/  ← داده‌ی جمع‌آوری‌شده (اولین snapshot واقعی از API زنده)
```

## انتخاب اولویت (سپتامبر ۲۲۶)
بر اساس دو تحقیق، اولویت اول: **آربیتراژ بازار پیش‌بینی (Kalshi)** — کم‌رقابت‌ترین
فزای زنده، API رایگان، و edge قفل‌شده بعد از اجرای هر دو پا. جزئیات: `docs/`.

## شروع سریع

```bash
cd kalshi-arb
python3 -m unittest discover -s tests -v        # 30 تست آفلاین
python3 -m kalshi_arb.bot --once --fixture tests/fixtures/markets_sample.json
python3 -m kalshi_arb.bot --loop --interval 15  # اسکن پیوسته روی API زنده
```
