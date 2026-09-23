# النشر التلقائي إلى Hostinger (المرحلة 3c)

بعد ما الـ CI ينجح على `main`، بيتشغّل **job الـ deploy** اللي بينقل الملفات إلى
الاستضافة عبر SSH (rsync) — **من غير ما يطبق migrations تلقائيًا** (ده قرار مسئول).

## ✅ الوضع الحالي (تم تنفيذه في 2026-09-23)

| العنصر | الحالة |
|---|---|
| الكود الجديد على الإنتاج | تم — السيرفر على آخر commit `7890a51` (سحب Git من hPanel) |
| اتصال SSH بمفتاح الـ deploy | شغال — المفتاح العام متضاف في `~/.ssh/authorized_keys` |
| بيانات الإنتاج | **سليمة وبتتقرا زيّ ما هي** — 207 عميل / 100 فاتورة / 12 مورّد / 135 مسافر (تحقق عملي عبر الطباعة العامة الموّقعة: فاتورة `260084` = `FEHMI BEJI` = `R$ 8.101,88`) |
| PHP على الـ server | 8.2.33 (≥ 8.1 المطلوب) |
| `.env` / `uploads/` / `DB/` | سليمين — rsync مستبعدهم نهائيًا |
| Migrations | `001_base_schema.sql` **pending** (لم يُطبق — السيرفر مبني من schema.sql قديم). التطبيق إضافي فقط (create-if-not-exists بلا INSERTs) ولا يستدعي أي إجراء قبل تفعيل التلقائي |

### بيانات الاتصال الفعلية

- Host: `45.132.157.91` (port `65002`) — user: `u834141812` — deploy path: `/home/u834141812/domains/pos.kamaltur.com/public_html`
- DB الإنتاج: `u834141812_pos` (34 جدول: 20 الأساسية + 13 legacy غير مستخدمة + `schema_migrations`)
- المفتاح العام: `ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPqX0HU/a9XxiCvg+FudzGcCBSLhl2kf9IVH/kDBDioB github-actions-deploy@pos-saas`

### اللي لسه محتاج منك (لتفعيل النشر التلقائي)

1. **GitHub Secrets** (انظر الجدول أدناه — كل القيم معروفة ومذكورة في هذا الملف).
2. **Variable** `DEPLOY_ENABLED = true` في GitHub.

> ⚠️ **درس مهم (تم إصلاحه 2026-09-23)**: `assets/dist/` (مخرجات Vite) **مش متعقبة في git** (gitignored).
> السحب اليدوي من hPanel Git **مش بيجيبها** — فالصفحات كانت بتترندر من غير CSS.
> **الـ CI deploy بيعمل `npm run build` قبل rsync أوتوماتيك** — فمفيش مشكلة في المسار التلقائي.
> لو سحبت يدويًا في أي وقت: شغّل `cd frontend && npm ci && npm run build` ثم ارفع `assets/dist/`
> لمجلد `assets/` على السيرفر (نفس المسار بتاع `pos.kamaltur.com` فقط).

## إعداد لمرة واحدة

### 1) من لوحة Hostinger (hPanel)

1. **فعّل SSH**: hPanel → Advanced → SSH Access → فعّل الوصول
   (هتلاقي الـ host والـ port — غالبًا `22` أو `65002` حسب الخطة).
2. **حدد PHP 8.1+**: hPanel → Websites → الموقع → PHP Configuration →
   اختر PHP 8.1 أو أعلى (الكود بيستخدم `match` و `str_starts_with` و strict types).
3. **ضيف المفتاح العام في السيرفر** (فيه المفتاح مكتوب تحت في القسم الأخير):
   ```bash
   ssh usuario@SEU_HOST   # ادخل السيرفر من الطرفية بتاعتك مرة واحدة
   mkdir -p ~/.ssh && chmod 700 ~/.ssh
   echo "ssh-ed25519 AAAA... github-actions-deploy@pos-saas" >> ~/.ssh/authorized_keys
   chmod 600 ~/.ssh/authorized_keys
   # جرب من جهازك المحلي:
   ssh -i ~/.ssh/pos-saas-hostinger-deploy -p PORT usuario@HOST "echo ok"
   ```
4. **تأكد إن `.env` بتاع الإنتاج موجود** في مسار التطبيق على السيرفر:
   ```bash
   cd ~/domains/SEU_DOMINIO/public_html   # المسار بتاع التطبيق
   # لو مش موجود، انسخه من .env.example أو ابنيه، لازم يبقى فيه:
   #   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
   #   PUBLIC_LINK_SECRET  (نص ≥ 32 حرف)
   ```
   > rsync **مستبعد** `.env` و `.env.*` — ملف الإنتاج على السيرفر مش هيتغير أبدًا.

### 2) من GitHub (Settings → Secrets and variables → Actions)

**Secrets** (اضغط "New repository secret"):

| الاسم | القيمة |
|---|---|
| `HOSTINGER_SSH_HOST` | `45.132.157.91` |
| `HOSTINGER_SSH_PORT` | `65002` |
| `HOSTINGER_SSH_USER` | `u834141812` |
| `HOSTINGER_SSH_KEY` | **محتوى المفتاح الخاص** — كله (من `-----BEGIN OPENSSH PRIVATE KEY-----` للنهاية) — اقرأه من جهازك: `cat ~/.ssh/pos-saas-hostinger-deploy` |
| `HOSTINGER_DEPLOY_PATH` | `/home/u834141812/domains/pos.kamaltur.com/public_html` |
| `PROD_URL` (اختياري) | `https://pos.kamaltur.com` — للتأكد بعد النشر |

**Variable** (تبويب Variables):

| الاسم | القيمة |
|---|---|
| `DEPLOY_ENABLED` | `true` (مقفول `false`/فاضي لحد ما تضيف كل الـ secrets) |

## أول نشر (بأمان)

الـ deploy جوه الـ CI وبيشتغل على push لـ `main` بعد ما الـ tests تنجح. بس الأول:

1. **اعمل backup للداتابيز**: من hPanel → Databases → Export (mysqldump).
2. **شوف حالة الـ migrations من غير ما تطبق**: ادخل السيرفر واعمل:
   ```bash
   cd ~/domains/SEU_DOMINIO/public_html
   php bin/migrate.php status
   ```
   شوف الـ pending migrations — **لو في حاجة غريبة قارنها بـ `database/migrations/`**
   (schema الإنتاج مش متعقب في git، الملفات ممكن تختلف شوية).
3. لما تكون مرتاح، **فعّل `DEPLOY_ENABLED = true`** في Variables.
4. أول push بعدها هيجيب الكود الجديد + يعرض `migrate status` في الـ log.

## إزاي الشغل بيمشي

| الحدث | اللي بيحصل |
|---|---|
| push / PR على `main` | الـ CI بيشغّل كل الاختبارات + البناء |
| push على `main` (والـ tests خضر + DEPLOY_ENABLED=true) | rsync للملفات → فحص `php -l` + `migrate status` على السيرفر |
| `workflow_dispatch` (زر "Run workflow" في تبويب Actions) | نفس اللي فوق، وممكن تختار **Apply pending migrations** لو عايز تطبق الـ migrations |
| أي وقت | الـ migrations **مش بتتطبق أوتوماتيك** — دايمًا يدوي وبتوعاك |

## اللي التطبيق مش هيلامسه

rsync بيستبعد: `.git`, `.env*` (بيحمي بياناتك), `uploads/` (ملفات العملاء),
`DB/`, `backups/`, `debug/`, `vendor/` (مش محتاجينه — فيه autoloader خفيف في
`inc/autoload.php`), `tests/`, `docs/`, `tools/`, `local-setup/`, `frontend/`,
`public/`.

لو السيرفر ركب على وش deployed بشكل فاضل: git revert + push → بينشر تاني تلقائيًا.

---

## مفتاح الـ deploy (اتولد مخصوص للنشر من GitHub Actions)

**المفتاح العام** (يضاف في `~/.ssh/authorized_keys` على السيرفر):

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPqX0HU/a9XxiCvg+FudzGcCBSLhl2kf9IVH/kDBDioB github-actions-deploy@pos-saas
```

**المفتاح الخاص** موجود على هذا الجهاز في:
`~/.ssh/pos-saas-hostinger-deploy` — محتواه هو اللي يتحط في سر `HOSTINGER_SSH_KEY`.
(مفيهوش passphrase — إجباري عشان الـ CI يشتغل من غير تدخل.)