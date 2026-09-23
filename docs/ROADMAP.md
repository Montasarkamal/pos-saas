# خطة تطوير KAMALTUR POS — من "بدائي" إلى حديث وعصري

> تاريخ الإعداد: 2026-09-23
> القاعدة الأساسية: **لا نكسر النظام الشغال**. التطبيق في إنتاج حي على Hostinger، فأي تطوير
> بيمشي بطريقة "الاستبدال التدريجي" (Strangler Pattern): كل مرحلة تخلّي المشروع
> قابل للنشر وبيشتغل، وبنحدّث جزء وراء جزء.

---

## ✅ سجل الإنجاز (Progress Log)

| التاريخ | ما تم | المرحلة |
|---|---|---|
| 2026-09-23 | Composer + PSR-4 autoload (`Kamaltur\` → `src/`) | 0 |
| 2026-09-23 | نظام Migrations (`bin/migrate.php` + `database/migrations/`) + `schema_migrations` | 0 |
| 2026-09-23 | Smoke tests (`tests/smoke.php` — 8 فحوصات ALL OK) | 0 |
| 2026-09-23 | حذف `cadastro/` (محفوظ في فرع `archive/cadastro`) | 0 |
| 2026-09-23 | خط إنتاج واجهة: Vite 6 + Tailwind 4 + Alpine.js → `assets/dist/` | 2 |
| 2026-09-23 | Design System أولي + صفحة **Login** جديدة | 2 |
| 2026-09-23 | Layout تطبيق جديد (`inc/layout.php`: سايد بار داكن + توب بار) | 2 |
| 2026-09-23 | **Dashboard** محوّل (KPIs + رسم شهري + donut reembolsos + جداول) | 2 |
| 2026-09-23 | **Vendas** (`sales/index.php`) محوّلة (فلاتر + sort + PNR copy + Mark Pago AJAX) | 2 |
| 2026-09-23 | **Clientes** (`clients/index.php`) محوّلة (بحث + فلاتر + نسخ بالضغط) | 2 |
| 2026-09-23 | **Fornecedores** (`suppliers/index.php`) محوّلون | 2 |
| 2026-09-23 | **Reembolsos** (`refunds/index.php`) محوّلة (فلاتر status + badges ملوّنة) | 2 |
| 2026-09-23 | **Relatórios** (`reports/index.php`) محوّلة (KPIs + Chart.js بألوان الـ brand) | 2 |
| 2026-09-23 | **Configurações** (`settings/index.php`) محوّلة (شبكة بطاقات إجراءات) | 2 |
| 2026-09-23 | **Venda (detalhe)** `sales/show.php` محوّلة (البطاقات + AJAX status + نسخ PNR) | 2 |
| 2026-09-23 | **Nova Venda** `sales/create.php` محوّلة بالكامل (trip cards + TomSelect + سويتشات + rule radios) | 2 |
| 2026-09-23 | **Editar Venda** `sales/edit.php` محوّلة بالكامل | 2 |
| 2026-09-23 | **Master Dashboard** `master/dashboard.php` محوّل (KPIs + Empresas + Saúde/Ferramentas + نشاط) | 2 |
| 2026-09-23 | Demo data seeder (`local-setup/seed-demo-data.php`) + إصلاح باگ إدراج العملاء | — |
| 2026-09-23 | إصلاح باگ خفي: `sales/create.php` كان ناقص `ob_start()` — الفورم كان بيترندر بره الـ shell + guard رجعي في smoke | 2 |
| 2026-09-23 | مكوّن autocomplete vanilla بيحل محل jQuery UI (بيكلم نفس `search_clients.php`) | 2 |
| 2026-09-23 | **Novo Cliente** `clients/create.php` محوّلة (rule radios + autocomplete "Trabalha Na") | 2 |
| 2026-09-23 | **Editar Cliente** `clients/edit.php` محوّلة (نفس الـ autocomplete) | 2 |
| 2026-09-23 | **Ficha Cliente** `clients/show.php` محوّلة (بطاقة معلومات + الإجراءات/WhatsApp + جدول المبيعات القابل للفرز + تحديث status فوري) | 2 |
| 2026-09-23 | **Novo/Editar Fornecedor** `suppliers/create.php` + `edit.php` محوّلان | 2 |
| 2026-09-23 | **Ficha Fornecedor** `suppliers/show.php` محوّلة (badges + آخر الفواتير) | 2 |
| 2026-09-23 | **Usuários** `users/index.php` + `create.php` + `edit.php` محوّلان (badges Cargo/Status + نسخ الإيميل + حماية الماستر) | 2 |
| 2026-09-23 | مسح شامل: 22 صفحة محوّلة — 200 + صفر كلاسات Tabler قديمة + صفر أخطاء PHP | 2 |
| 2026-09-23 | **Exportar Vendas** `sales/export.php` محوّلة (نموذج فلاتر + إشعار CSV/JSON) | 2 |
| 2026-09-23 | **Profile** `profile.php` محوّل (بطاقة بيانات + حقول للقراءة فقط + تغيير كلمة المرور) | 2 |
| 2026-09-23 | **Sobre** `settings/about.php` محوّلة (hero بالشعار + شبكة معلومات + قائمة الميزات) | 2 |
| 2026-09-23 | **Dados da Empresa** `settings/company.php` محوّلة (أربع كروت: agência/endereço/bancários/arquivos) | 2 |
| 2026-09-23 | **Novo Reembolso** `refunds/create.php` محوّل (نموذج كامل + upload comprovante) | 2 |
| 2026-09-23 | **Detalhe Reembolso** `refunds/show.php` محوّل (badges حالة ملوّنة + دوائر ألوان timeline + إجراءات) | 2 |
| 2026-09-23 | **Editar Reembolso** `refunds/edit.php` محوّل (بانر recibo + ملخص + timeline) | 2 |
| 2026-09-23 | **Listas do Sistema** `settings/lists.php` محوّلة (تبويبات بـ CSS أصلي + صفوف ديناميكية + رفع شعارات الطيران) | 2 |
| 2026-09-23 | **Backup** `settings/backup.php` محوّل (بطاقات إجراءات + معاينة SQL + منطقة الخطر purge بنفس الـ JS/ID) | 2 |
| 2026-09-23 | **Nova Venda de Serviço** `services/create.php` محوّلة (لوحات حسب النوع + TomSelect + صفوف ضيوف/غرف ديناميكية — الـ redirect الأصلي لـ sales/create محفوظ) | 2 |
| 2026-09-23 | **مسح شامل ختامي**: 33 صفحة — كل الصفحات على الـ shell القديم اتحوّلت (صفر refs لـ inc/header.php) — 200 + aside قبل main + صفر Tabler + field-name parity + smoke guard موسّع لـ 30 صفحة | 2 |

| 2026-09-23 | **اختبارات Pest 4** (المرحلة 3a): `composer require --dev pestphp/pest` + scaffold + `Kamaltur\Money` (هامش/moeda) + اختبارات helpers/auth (CSRF + roles + agency scope) + InvoicesLib (رقم الفاتورة داخل transaction بـ ROLLBACK) — **20 passed (70 assertions)** | 3 |
| 2026-09-23 | استخراج حساب الهامش لـ `src/Money.php` واستخدامه في `sales/create.php` + `sales/edit.php` (نفس الصيغة بالظبط — سلوك مطابق) + `composer test` بتشغّل smoke ثم pest | 3 |
| 2026-09-23 | **CI (المرحلة 3b)**: `.github/workflows/ci.yml` (PHP 8.3 + MySQL 8 service + lint + migrations + seed + Pest + smoke + Vite build + HTTP smoke) + `tests/http-smoke.sh` (login حقيقي + 12 صفحة — بيتعاد استخدامه محليًا بـ `composer test:http`) | 3 |
| 2026-09-23 | **Deploy (المرحلة 3c — جاهز)**: job `deploy` في الـ CI — rsync عبر SSH إلى Hostinger على push لـ `main` بعد نجاح الـ tests + fحص `migrate status` (من غير تطبيق تلقائي) + تطبيق migrations يدوي بـ workflow_dispatch. مقفول ورا variable `DEPLOY_ENABLED` + `docs/DEPLOY.md` | 3 |
| 2026-09-23 | **إصلاح باگ autoload**: التطبيق كان بيستخدم `\Kamaltur\Money::margin()` في صفحات POST من غير تحميل composer autoload (fatal عند الإرسال) — `inc/autoload.php` خفيف (بيحل `Kamaltur\*` من `src/` مع أو من غير vendor) مربوط في `inc/db.php` + تحقق POST حقيقي (200 من غير أخطاء) | 3 |
| 2026-09-23 | **نشر الإنتاج الفعلي (Phase 3c)**: السيرفر على آخر commit `7890a51` بالكود الجديد (سحب عبر Git من hPanel) — المفتاح العام اتضاف في `authorized_keys` عبر `ssh-copy-id`، اتصال SSH بالمفتاح شغال (host `45.132.157.91` port `65002` user `u834141812`)، PHP 8.2.33، `.env` و `uploads/` و `DB/` سليمين | 3 |
| 2026-09-23 | **تحقق "يقرأ نفس البيانات" على الإنتاج ✅**: DB `u834141812_pos` (34 جدول = 20 الأساسية موجودة + 13 legacy غير مستخدمة + schema_migrations) — العدادات: 207 عميل، 100 فاتورة، 12 مورّد، 135 مسافر، 249 segment، 2 agencies، 2 users — `health.php` عبر الويب `{"status":"ok","database":"ok"}` — **الطباعة العامة موقّعة** (`sales/print.php` + `voucher.php` بــ `PUBLIC_LINK_SECRET`) عرضت الفاتورة الحقيقية `260084` بالعميل `FEHMI BEJI` والمبلغ `R$ 8.101,88` بالظبط (مطابق للـ DB) من غير أخطاء PHP | 3 |
| 2026-09-23 | أدوات تشخيص للإنتاج (read-only): `tools/{snapshot_counts,describe_prod,prod_users,prod_samples,prod_public_link,prod-verify}.{php,sh}` — محظورة من الـ deploy (`tools/` مستبعدة) | 3 |

**نهاية المرحلة 2 🎉**: كل صفحات النظام (غير كُتيّبات الطباعة) على الـ layout الجديد — `inc/header.php` و `inc/footer.php` بقوا dead code (لا حذفها الآن احتياطًا للتراجع).

**المرحلة 3a (اختبارات Pest) مكتملة ✅**: 20 اختبار نجحوا (70 assertions) — التغطية: رقم الفاتورة (YY#### + incremental + ROLLBACK) + الهامش (الصيغة الدقيقة) + CSRF (valid/invalid/null/stability) + roles + multi-tenancy scope + helpers (brl/date/status).

**المرحلة 3b (CI — GitHub Actions) مكتملة ✅**: `.github/workflows/ci.yml` على كل push/PR — PHP 8.3 + MySQL 8 service (DB جديدة `kamaltur_test`) → composer install + `php -l` لكل الملفات + migrations + seed + Pest + smoke + `npm ci` + build Vite + **HTTP smoke** (`tests/http-smoke.sh`: login حقيقي + مسح 12 صفحة على الـ shell الجديد). التالي: 3c (deploy تلقائي لـ Hostinger — يحتاج GitHub Secrets من عندك).

**المرحلة 3c (Deploy) — الكود على الإنتاج بالفعل ✅ + التفعيل التلقائي محتاج خطوتين من عندك ⏳**: الكود الجديد (7890a51) شغال على `pos.kamaltur.com` (سحب Git من hPanel)، المفتاح العام متضاف في السيرفر، والبيانات اتأكدنا إنها بتتقرا زيّ ما هي (أرقام مطابقة). **متبقّي لتفعيل التلقائي**: (1) إضافة الـ GitHub Secrets الستة + `PROD_URL`، (2) تفعيل variable `DEPLOY_ENABLED=true` — كل التفاصيل في `docs/DEPLOY.md`.

---

## 1) الوضع الحالي (التشخيص الصادق)

| النقطة | الحالة الحالية | المشكلة |
|---|---|---|
| البنية | فلات PHP — لا Framework ولا Composer ولا Autoload | كل صفحة Controller + View مع بعض؛ ملفات ضخمة (أكبرها `sales/create.php` = 70KB) |
| قاعدة البيانات | الـ Schema **مش متعقّب في Git** (فيه `schema.sql` محلي بس) | أي بيئة جديدة محتاجة إعادة بناء يدوية؛ ولا فيه Migrations |
| الواجهة | CSS قديم + Bootstrap/Tabler قديم | مش "عصرية" مقارنة بالمعايير دلوقتي (بدون components ولا design system) |
| الاختبارات | صفر | أي تغيير ممكن يكسر شغل شغال |
| كود ميت | `cadastro/` (نسخة قديمة مكررة 21 ملف) + `tools/` و`debug/` | تشتيت وخطورة |
| النشر | يدوي عبر Git على Hostinger | مفيش CI/CD ولا بناء تلقائي |
| الأمان | **ممتاز فعلًا**: Prepared Statements + CSRF + password_hash + عزل multi-tenant | لا يحتاج تدخل — نحافظ عليه |

**الخلاصة**: المشروع مبني على أساسات أمان سليمة، لكن العيوب في *التنظيم* و*الواجهة* مش في *الأمان*.

---

## 2) المبادئ اللي بنمشي عليها

1. **استمرارية التشغيل**: بعد كل مرحلة، التطبيق يبقى شغال وقابل للنشر.
2. **التحول التدريجي**: نقل صفحة-صفحة مش إعادة كتابة من الصفر (إلا لو اختارنا Laravel — انظر الخيار B).
3. **كل حاجة متعقبة**: Schema + Migrations + الاختبارات كلها تروح Git.
4. **تجربة مطور + مستخدم مع بعض**: بنية نظيفة + واجهة عصريّة.

---

## 3) خيارات الاتجاه

| | **الخيار A — تطوير تدريجي منظم** (مستحسن) | **الخيار B — إعادة كتابة بـ Laravel** | **الخيار C — واجهة أولاً** |
|---|---|---|---|
| الفكرة | نحافظ على PHP الحالية ونعيد تنظيمها بطبقات + UI حديث | نقل كامل إلى Laravel + Blade + Livewire | نحدّث الـ UI بس الأول |
| الزمن | 3–5 أسابيع (مراحل) | 6–12 أسبوع + أسبوعين اختبار | 1–2 أسبوع |
| المخاطرة | منخفضة (بصمة تغيير صغيرة كل مرة) | مرتفعة (rewrite كامل + schema جديد + deploy جديد) | منخفضة جداً |
| توافق Hostinger | تام | يحتاج composer install + docroot `/public` | تام |
| النتيجة | عصرية 90% وتحت السيطرة | عصرية 100% لكن إعادة بناء | شكل عصري بس البنية القديمة |

---

## 4) خطة التنفيذ — المراحل (خاصة بالخيار A، وقابلة للتعديل)

### المرحلة 0 — تثبيت القاعدة (يومان)
- ✅ الـ schema يتحول لـ **Migrations** رسمية (نبدأ بطبقة `Migrations` بسيطة أو Phinx).
- ✅ إدخال **Composer + PSR-4 autoload** بدون تغيير سلوك الصفحات.
- ✅ تنظيم `inc/` لوحة تحميل مركزي + حذف الدوال المكررة.
- ✅ حذف `cadastro/` نهائياً (بعد أرشفة نسخة في فرع `archive/cadastro` لو محتاجينها).
- ✅ عزل `tools/` وراء `local-setup/` + حماية أقوى للسيرفر المحلي.
- 🧪 أول اختبارات (Smoke tests للوجين والصفحات الأساسية).

### المرحلة 1 — بنية الكود (أسبوعان)
- ✅ **Router واحد** (Front controller) ليحل محل كل `header('Location: /x.php')` وتنفيذ الصفحات.
- ✅ فصل **Controllers** (منطق) عن **Views** (عرض) — صفحة صفحة، نبدأ بالأهم: `sales/*` ثم `clients`, `suppliers`, ثم الباقي.
- ✅ طبقة **Services/Repositories** للجداول الحرجة: `Invoices`, `Clients`, `Passengers`.
- ✅ كل Print/layout يتمركز في `inc/layouts/` مع التوكنات.
- ✅ إضافة `DEBUG=0/1` في `.env` لتبديل الأخطاء المرئية.

### المرحلة 2 — الواجهة العصرية (أسبوعان)
- ✅ **Tailwind CSS 4 + Alpine.js + Vite** — نظام تصميم موحد (Sidebar داكن، Cards، Tables ناعمة، Dark Mode).
- ✅ **مكونات جاهزة**: فلاتر، مودال، زر عائم "إضافة شغل"، Skeletons أثناء التحميل.
- ✅ **رسوم بيانية** للـ Dashboard (Chart.js) بدل الأرقام الجافة.
- ✅ تحويل الصفحات واحداً واحداً مع الحفاظ على **قوالب الطباعة (Faturas/Voucher/Recibos)** بوضوح.
- ✅ متجاوب كامل مع الموبايل (المستخدمين بتوع الوكالة بيفتحوا الموبايل كتير).

### المرحلة 3 — الجودة و CI/CD (أسبوع)
- ✅ **Pest**: توليد رقم الفاتورة، حساب الهامش، عزل الـ multi-tenancy، الـ CSRF (+helpers +roles) — 20 اختبار أخضر.
- ✅ **GitHub Actions** (`.github/workflows/ci.yml`): PHP 8.3 + MySQL 8 service + lint كل الملفات + migrations + seed + Pest + smoke + بناء Vite + HTTP smoke على كل push/PR.
- ✅ **Deploy تلقائي لـ Hostinger** (جوه نفس الـ workflow): job `deploy` بعد نجاح الـ tests على push لـ `main` — rsync عبر SSH مع استبعاد `.env`/`uploads`/`vendor`، فحص `php bin/migrate.php status` (من غير تطبيق)، تطبيق الـ migrations يدوي بس عبر `workflow_dispatch` (`apply_migrations`). **محتاج يكمل: يفضل تضيف الـ GitHub Secrets + تفعّل variable `DEPLOY_ENABLED`** — الدليل كامل في `docs/DEPLOY.md`.

### المرحلة 4 — ميزات جديدة فوق القاعدة الحديثة (متواصلة)
- Dashboards تفاعلية بالكامل مع فلاتر زمنية وحجز عملات.
- تصدير PDF احترافي للفواتير والتقارير.
- بحث ذكي بـ AJAX (بحث العملاء/الفواتير أثناء الكتابة).
- دعم لغتين (pt-BR / EN وقابل للإضافة العربية) — حالياً UI كله pt-BR.
- API داخلي نظيف للمرحلة القادمة (تطبيق موبايل مستقبلاً).

---

## 5) أولوية البداية المقترحة

> **ابدأ بالمرحلة 0 + أول أسبوع من المرحلة 2** — يعني:
> قاعدة نظيفة + أول 3 صفحات بشكل عصري — في خلال أسبوع تشوف فرق ملموس
> في الشكل مع استقرار في الأساس.

---

## 6) ما لن نغيّره (لأنه سليم)
- نموذج الأمان الحالي (prepared statements, CSRF, password_hash, agency scoping).
- منطق الأعمال المحسوب جيداً (عمولة، هامش، أرقام فواتير).
- هيكل بيانات الـ DB (نضيف Migration مش نعيد التصميم).