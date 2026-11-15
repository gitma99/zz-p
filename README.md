# آموزش نصب و راه‌اندازی ZZPanelBot با داکر

این ریپازیتوری اکنون روی ساختار **Docker Compose** اجرا می‌شود. تمام مراحل نصب، به‌روزرسانی، بکاپ و بازیابی با داکر انجام می‌شود.

---

## روش اول: نصب خودکار با اسکریپت zzbot (توصیه شده)

ساده‌ترین راه برای نصب و مدیریت پروژه، استفاده از اسکریپت مدیریت خودکار است. این اسکریپت پیش‌نیازها را بررسی و نصب می‌کند، آخرین نسخه را در مسیر `/srv` مستقر می‌کند و دستور سراسری `zzbot` را جهت مدیریت آسان برای شما ثبت می‌نماید.

### مراحل نصب گام به گام:
1. **دریافت توکن گیتهاب PAT**: برای دسترسی به ایمیج‌های خصوصی گیت‌هاب، یک توکن دسترسی شخصی (PAT) با مجوز `read:packages` ایجاد کنید. این توکن را در مرحله بعد وارد خواهید کرد.

2. **اتصال به سرور**: با استفاده از SSH به سرور لینوکس خود با کاربر `root` یا کاربری با دسترسی `sudo` متصل شوید.

3. **اجرای دستور نصب**: دستور زیر را در ترمینال کپی و اجرا کنید:
   ```bash
   bash <(curl -Ls "https://raw.githubusercontent.com/gitma99/zz-p/main/setup.sh?t=$(date +%s)" )
   ```

4. **تنظیم اطلاعات محیطی**: اسکریپت فایل `.env` را ایجاد کرده و آن را با ویرایشگر `nano` باز می‌کند. اطلاعات مورد نیاز (مانند توکن ربات و دیتابیس) را وارد کنید. برای ذخیره در نانو کلید `Ctrl+O` و سپس `Enter` و برای خروج `Ctrl+X` را فشار دهید.

5. **وارد کردن توکن گیت‌هاب**: در صورت درخواست، توکن دسترسی شخصی (PAT) خود را وارد کنید تا اسکریپت بتواند ایمیج‌های مورد نیاز را از گیت‌هاب دریافت کند.

6. **دریافت ایمیج‌ها**: اسکریپت ایمیج‌های مورد نیاز را از گیت‌هاب رجیستری دریافت (Pull) می‌کند.

7. **فعال‌سازی SSL**: در انتهای نصب، گواهی SSL دامنه خود را از طریق Let's Encrypt فعال کنید.
> توجه: داشتن SSL برای کار با ربات از الزامات است.

پس از پایان نصب، در هر زمان و در هر فولدری به سادگی با تایپ دستور زیر می‌توانید به منوی مدیریتی دسترسی داشته باشید:
```bash
zzbot
```

### راهنمای گزینه‌های منوی مدیریت `zzbot`:

**Service Management**
* **1) Start Services**: راه‌اندازی سرویس‌ها (منوی تعاملی برای انتخاب سرویس خاص یا همه).
* **2) Stop Services**: توقف سرویس‌ها (منوی تعاملی برای انتخاب سرویس خاص یا همه).
* **3) Restart Services**: راه‌اندازی مجدد سرویس‌ها (منوی تعاملی برای انتخاب سرویس خاص یا همه).
* **4) Hard Restart (recreate all)**: توقف کامل (`down`) و اجرای مجدد (`up -d`) همه سرویس‌ها.
* **5) Install ZZPanel**: راه‌اندازی و نصب کامل پروژه (تنها برای اولین بار یا نصب مجدد).
* **6) Update ZZPanelBot**: دریافت نسخه‌ی انتخابی (پیش‌فرض: جدیدترین) و اعمال بروزرسانی روی کانتینرها. قبل از تغییر، از `.env` نسخه‌ی پشتیبان گرفته می‌شود (۳ بکاپ اخیر در `/tmp/zzpanel-env-backup.*`) و متغیرهای جدید `.env.example` خودکار به `.env` افزوده می‌شوند. انتخاب نسخه‌ی قدیمی‌تر از نسخه‌ی فعلی هشدار می‌دهد (سکم دیتابیس ممکن است به عقب سازگار نباشد).
* **7) Uninstall ZZPanel**: پاک کردن کامل تمامی کانتینرها، ایمیج‌ها، دیتابیس و حذف دستور `zzbot`.

**Configuration**
* **8) Edit .env**: باز کردن سریع فایل تنظیمات محیطی ربات جهت ویرایش متغیرها.
* **9) Initialize SSL (Let's Encrypt)**: راه‌اندازی و تمدید گواهی SSL برای امنیت اتصالات دامنه.

**Maintenance**
* **10) Backup Database & Files**: ایجاد فایل پشتیبان دستی از دیتابیس و فایل‌های کلیدی.
* **11) Restore Database & Files**: بازیابی اطلاعات دیتابیس از روی فایل‌های بکاپ موجود.

**Diagnostics**
* **12) View Live Logs (all)**: مشاهده لاگ‌های زنده تمامی سرویس‌ها به صورت همزمان (خروج با `Ctrl+C`).
* **13) View Logs (specific service)**: مشاهده لاگ‌های زنده یک سرویس خاص (مانند `app`, `nginx`, یا `backup`).
* **14) View Log Files (host filesystem)**: مرور فایل‌های لاگ ذخیره‌شده روی دیسک میزبان (`/srv/logs/...` و `/srv/data/backups`) با امکان انتخاب سرویس، فایل و مشاهده‌ی پیوسته (`less`).
* **15) Run Health Check**: عیب‌یابی خودکار و بررسی سلامت کلی ربات و سرور (پزشک ربات).

**Database Tools**
* **16) Start phpMyAdmin**: راه‌اندازی phpMyAdmin (با پروفایل `phpmyadmin`).
* **17) Stop phpMyAdmin**: توقف phpMyAdmin.

**System**
* **18) Install zzbot Command**: نصب یا نصب مجدد دستور سراسری `zzbot` در `/usr/local/bin` (مثلاً اگر در حین نصب ثبت نشده باشد).
* **19) Update zzbot Menu**: به‌روزرسانی دستور `zzbot` از آخرین نسخه موجود در ریپازیتوری (لانچر همیشه تازه‌ترین `setup.sh` از شاخه‌ی `main` را دانلود می‌کند).
* **20) Update Menu Cache**: پاک کردن و بارگذاری دوباره‌ی کش‌های منوی مدیریتی (نسخه، سرویس‌ها، پنل‌ها) برای سرعت بخشیدن به لود منو.

### استفاده از خط فرمان (بدون منوی تعاملی)

علاوه بر منوی تعاملی، می‌توانید مستقیماً از دستورات زیر در ترمینال استفاده کنید:

```bash
zzbot install [version]      # نصب اولیه (مثلاً: zzbot install v3.3.0)
zzbot update [version]       # به‌روزرسانی به نسخه‌ی مشخص یا جدیدترین
zzbot diagnose [category]    # اجرای بخش مشخصی از عیب‌یابی
```

دستور `diagnose` دو گزینه دارد:
- `--fix`: اعمال تعمیرات خودکار بدون تأیید دستی.
- `--export`: ذخیره‌ی گزارش در یک فایل.

دسته‌بندی‌های قابل اجرا: `all`, `host`, `containers`, `db`, `web`, `ssl`, `telegram`

### عیب‌یابی خودکار و بررسی سلامت سیستم (پزشک ربات):
اگر ربات شما به هر دلیلی کار نکرد یا پیام‌ها ارسال نشدند، نیازی به بررسی‌های پیچیده فنی نیست. کافی است از گزینه **15) Run Health Check** در منوی `zzbot` استفاده کنید یا دستور زیر را در ترمینال بزنید:

```bash
zzbot diagnose
```

این بخش به صورت خودکار موارد زیر را با رنگ‌های واضح (`[PASS]` سبز، `[WARN]` زرد، `[FAIL]` قرمز) بررسی می‌کند:
1. **منابع سرور** (`host`): فضای خالی و inode دیسک، حافظه و swap، و وضعیت اشغال پورت‌های 80/443.
2. **کانتینرها** (`containers`): وضعیت و healthcheck همه‌ی سرویس‌ها؛ کانتینرهای خاموش یا OOMKilled به همراه آخرین خطوط لاگ آن‌ها.
3. **دیتابیس** (`db`): زنده بودن MySQL، اصالت کاربر و رمز دیتابیس، اشباع‌نشدن pool اتصال، و اعمال‌شده بودن همه‌ی مایگریشن‌های `sql/migrations/`.
4. **وب‌سرور** (`web`): زنده بودن Apache2 و PHP-FPM، پاسخ HTTP داخلی، صحت مالکیت و دسترسی پوشه‌های لاگ (برای هر سرویس)، و بارگذاری ماژول APCu.
5. **دامنه و SSL** (`ssl`): صحت کافینگ Nginx، انقضای گواهی SSL، تطابق DNS دامنه با IP سرور، و تست HTTPS از داخل سرور.
6. **ارتباط با تلگرام** (`telegram`): چهار مرحله‌ی DNS، دسترسی TLS به `api.telegram.org` (بررسی فیلترینگ)، اعتبار توکن، و وضعیت وب‌هوک (آدرس، خطاها و صفِ پیام‌های در انتظار).

#### تعمیر خودکار مشکلات (Auto-Fix):
در صورت وجود مشکلاتی مانند به هم خوردن دسترسی فایل‌ها، قفل شدن وب‌هوک تلگرام یا خوابیدن کانتینرها، اسکریپت از شما می‌پرسد که آیا مایل به تعمیر خودکار هستید یا خیر. تعمیرات شامل بازگردانی دسترسی پوشه‌های لاگ، ثبت دوباره‌ی وب‌هوک با پاک‌سازی صفِ پیام‌های مانده، و ری‌استارت سرویس‌های معیوب (به ترتیب وابستگی) است. همچنین می‌توانید این کار را بدون نیاز به تایید دستی با دستور زیر انجام دهید:
```bash
zzbot diagnose --fix
```

#### ذخیره گزارش جهت ارسال به پشتیبانی (Export):
اگر مشکل با تعمیر خودکار حل نشد و خواستید فایل گزارش را برای فرد دیگری بفرستید تا شما را راهنمایی کند، از دستور زیر استفاده کنید:
```bash
zzbot diagnose --export
```
این دستور گزارشی در مسیر `/tmp/zzbot-diagnose-...log` با دسترسی `0600` ایجاد می‌کند. اطلاعات حساس سرور شما مانند **`BOT_TOKEN`، `DB_PASS` و `MYSQL_ROOT_PASSWORD` به صورت کاملاً خودکار در این فایل ستاره‌دار و مخفی (مَسک) می‌شوند** تا امنیت شما به خطر نیفتد.

---

## روش دوم: نصب دستی (پیش‌نیازها)

- **Docker** نصب شده باشد
- **Docker Compose** با پشتیبانی `docker compose` نصب شده باشد

### نصب Docker روی اوبونتو

```bash
# Add Docker's official GPG key:
sudo apt update
sudo apt install ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

# Add the repository to Apt sources:
sudo tee /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker $USER
sudo systemctl status docker
sudo docker run hello-world
sudo apt install -y nano unzip zip curl wget

# سپس از سیستم خارج و دوباره وارد شوید یا با `newgrp docker` تغییر گروه را اعمال کنید.
```

## دانلود و پیکربندی دستی ریلیز
1. ابتدا از قسمت Releases آخرین فایل `zzpanel-docker-setup.zip` را دانلود کنید.
2. فایل را در داخل سرور (میزان `BASE_DIR`، پیش‌فرض `/srv`) قرار دهید.

3. با دستور زیر فایل را اکسترکت کنید:
```bash
unzip zzpanel-docker-setup.zip
```

## تنظیمات محیط (`.env`)

یک فایل پیکربندی محیط بسازید یا از نمونه استفاده کنید:
```bash
cp .env.example .env
```

### جدول متغیرهای محیطی

| متغیر | شرح | مثال/پیش‌فرض | الزامی |
|-------|------|------------|--------|
| **BOT_TOKEN** | توکن ربات تلگرام | `123456:ABCdefGHIjklmnoPQRstuvWXYZ` | ✅ بلی |
| **CHAT_ID** | شناسه چت/کانال تلگرام برای اطلاع‌رسانی | `987654321` | ✅ بلی |
| **DOMAIN** | نام دامنه سرویس | `yourdomain.com` | ✅ بلی |
| **CERTBOT_EMAIL** | ایمیل برای گواهی Let's Encrypt | `admin@yourdomain.com` | ✅ بلی |
| **DB_NAME** | نام دیتابیس MySQL | `botpanel` | ✅ بلی |
| **DB_USER** | نام کاربر دیتابیس | `botpanel_user` | ✅ بلی |
| **DB_PASS** | رمز عبور دیتابیس | `strong_db_password` | ✅ بلی |
| **MYSQL_ROOT_PASSWORD** | رمز عبور root MySQL | `strong_root_password` | ✅ بلی |
| **BACKUP_TELEGRAM_TOKEN** | توکن ربات تلگرام برای ارسال بکاپ (اختیاری) | `123456:ABCdefGHIjklmnoPQRstuvWXYZ` | ❌ خیر |
| **BACKUP_CHAT_ID** | شناسه چت برای ارسال بکاپ (اختیاری) | `987654321` | ❌ خیر |
| **BACKUP_RETENTION_DAYS** | تعداد روز نگهداری فایل‌های بکاپ | `1` | ❌ خیر (پیش‌فرض: 1) |
| **BACKUP_INTERVAL_MINUTES** | فاصله‌ی بین بکاپ‌ها به دقیقه (جایگزین برنامه‌ی نیمه‌شب) | خالی = هر روز نیمه‌شب (Asia/Tehran) | ❌ خیر (حداقل: 5) |
| **BACKUP_MAX_RETRIES** | تلاش‌های مجاز برای هر بار بکاپ | `3` | ❌ خیر (حداقل: 1) |
| **APP_VERSION** | تگ ایمیج اپلیکیشن | `latest` | ❌ خیر |
| **LOG_LEVEL** | سطح لاگ اپلیکیشن | `WARNING` | ❌ خیر (`DEBUG`/`INFO`/`WARNING`/`ERROR`/`CRITICAL`) |
| **LOG_BUFFER_SIZE** | تعداد رکوردهای لاگ در بافر (حداقل 1) | `1000` | ❌ خیر |
| **LOG_RETENTION_DAYS** | روزهای نگهداری فایل‌های لاگ (حداقل 1) | `30` | ❌ خیر |
| **LOG_DIR** | مسیر لاگ‌های اپلیکیشن | پوشه‌ی پیش‌فرض لاگ | ❌ خیر |
| **LOG_TELEGRAM_ENABLED** | ارسال لاگ‌ها به تلگرام | `true` | ❌ خیر |
| **LOG_TELEGRAM_MIN_LEVEL** | حداقل سطح لاگ برای ارسال به تلگرام | `ERROR` | ❌ خیر |
| **LOG_TELEGRAM_DEDUP_SECONDS** | بازه‌ی حذف تکرار برای لاگ‌های ارسالی به تلگرام | `3600` | ❌ خیر |
| **LOG_TELEGRAM_DEDUP_ENABLED** | فعال بودن حذف تکرار لاگ‌های ارسالی به تلگرام | `false` | ❌ خیر |

**توضیحات اضافی:**
- `BACKUP_TELEGRAM_TOKEN` و `BACKUP_CHAT_ID`: اگر تنظیم نشوند، سیستم از `BOT_TOKEN` و `CHAT_ID` استفاده می‌کند
- `BACKUP_RETENTION_DAYS`: اگر تنظیم نشود، به طور پیش‌فرض 1 روز در نظر گرفته می‌شود و یک هشدار ثبت می‌شود
- `BACKUP_INTERVAL_MINUTES`: اگر خالی بماند، بکاپ هر روز در نیمه‌شب (Asia/Tehran) گرفته می‌شود
- `BACKUP_MAX_RETRIES`: تعداد تلاش‌ها برای هر اجرای بکاپ (پیش‌فرض 3)
- متغیرهای `LOG_*`: مقدار متغیر محیطی بر مقدار فایل `bot_config.json` ارجحیت دارد
- `APP_VERSION`: توسط اسکریپت `zzbot` هنگام نصب/به‌روزرسانی به‌صورت خودکار تنظیم می‌شود

---

## نصب و راه‌اندازی دستی
1. دانلود آخرین نسخه ریلیز از گیت‌هاب و اکسترکت آن در مسیر `/srv` یا مسیر دلخواه شما.

2. یک فایل `.env` بسازید یا از `.env.example` کپی کنید:
    ```bash
    cp .env.example .env
    ```
3. پیش از اجرای اولین `up`، پوشه‌های لاگ میزبان را با مالکیت مناسب بسازید. هر کانتینر با UID تصویر خودش لاگ می‌نویسد؛ اگر این پوشه‌ها وجود نداشته باشند، Docker آن‌ها را `root:root` می‌سازد و mysqld/nginx به‌صورت خاموش لاگ را از دست می‌دهند:
    ```bash
    mkdir -p /srv/logs/app /srv/logs/worker /srv/logs/mysql /srv/logs/nginx
    chown 33:33 /srv/logs/app /srv/logs/worker     # app/worker (www-data)
    chown 999:999 /srv/logs/mysql                  # mysql:8.0
    chown 101:101 /srv/logs/nginx                  # nginx alpine
    chmod 775 /srv/logs/app /srv/logs/worker /srv/logs/mysql /srv/logs/nginx
    ```
    (اسکریپت `zzbot` این مرحله را خودکار انجام می‌دهد.)
4. اگر نیاز به دسترسی به image از GitHub Container Registry دارید، ابتدا وارد شوید:
   ```bash
   echo "YOUR_GH_TOKEN" | docker login ghcr.io -u YOUR_GITHUB_USERNAME --password-stdin
   ```

   - `YOUR_GH_TOKEN` باید یک GitHub access token باشد که مجوز `write:packages` یا `read:packages` دارد.
   - اگر image عمومی است، نیازی به ورود وجود ندارد.

5. از image پیش‌ساخته استفاده کنید:
   ```bash
   docker compose pull
   ```

6. برای نصب و راه‌اندازی، باید گواهی Let's Encrypt را قبل از اجرای `docker compose up -d` بوت‌استرپ کنید:
 ```bash
 chmod +x /srv/docker/nginx/init-letsencrypt.sh
 bash /srv/docker/nginx/init-letsencrypt.sh
 ```

7. سپس سرویس‌ها را بالا بیاورید:
   ```bash
   docker compose up -d
   ```



## بروزرسانی دستی با Docker Compose

اگر image جدیدی ساخته یا منتشر کردید، کافی است:

```bash
docker compose pull app
docker compose up -d app
```

## مدیریت phpMyAdmin

phpMyAdmin به صورت پیش‌فرض اجرا نمی‌شود و با پروفایل `phpmyadmin` در Docker Compose قرار دارد. برای دسترسی به آن:

```bash
cd /srv && docker compose --profile phpmyadmin up -d phpmyadmin   # راه‌اندازی
cd /srv && docker compose --profile phpmyadmin down phpmyadmin    # توقف
```

دسترسی: `https://yourdomain.com/phpmyadmin/`

## بکاپ خودکار

بکاپ‌ها توسط سرویس `zzpanel_backup` در Docker Compose انجام می‌شوند. خروجی بکاپ‌ها روی میزبان در `data/backups/` قرار می‌گیرد.

- بکاپ اولیه بلافاصله پس از اجرای کانتینر ساخته می‌شود
- سپس هر روز در نیمه شب تهران یک بکاپ جدید ایجاد می‌شود

### تنظیمات تلگرام برای بکاپ

در فایل `.env` می‌توانید متغیرهای زیر را اضافه کنید تا نوتیفیکیشن بکاپ با بات یا چت متفاوت ارسال شود:

```env
BACKUP_TELEGRAM_TOKEN=your_telegram_bot_token
BACKUP_CHAT_ID=your_telegram_admin_chat_id
BACKUP_INTERVAL_MINUTES=60   # اختیاری: بکاپ هر ۶۰ دقیقه (حداقل 5)
BACKUP_MAX_RETRIES=3         # اختیاری: تلاش‌های مجاز در هر اجرای بکاپ (حداقل 1)
```

- `BACKUP_INTERVAL_MINUTES`: اگر خالی باشد، بکاپ هر روز در نیمه‌شب (Asia/Tehran) انجام می‌شود.
- `BACKUP_MAX_RETRIES`: در صورت شکست هر بار، تا ۳ بار (پیش‌فرض) با فاصله ۱۰ ثانیه تکرار می‌شود؛ پس از شکست کامل، تلاش بعدی بعد از ۱ ساعت خواهد بود.


## بکاپ دستی

1. اجرای اسکریپت مدیریت:
   ```bash
   zzbot
   ```
2. گزینه `10) Backup Database & Files` را انتخاب کنید.

3. گزینه Backup را انتخاب کنید. 

## بازیابی بکاپ
1. فایل بکاپ مورد نظر را در مسیر `/srv/data/backups/` قرار دهید.

2. اجرای اسکریپت مدیریت:
   ```bash
   zzbot
   ```
3. گزینه `11) Restore Database & Files` را انتخاب کنید.

4. گزینه `Restore` را انتخاب کنید و فایل بکاپ مورد نظر را انتخاب و تأیید کنید.

5. اسکریپت بازیابی در صورت انتخاب `y` به صورت خودکار برنامه را ری‌استارت می‌کند.

## لاگ‌ها و رفع مشکل

برای مشاهده لاگ زنده:

```bash
cd /srv && docker compose logs -f
```

یا فقط لاگ‌های سرویس خاص:

```bash
cd /srv && docker compose logs -f app
cd /srv && docker compose logs -f nginx
cd /srv && docker compose logs -f backup
```

لاگ بکاپ خودکار نیز روی میزبان در `/srv/data/backups/backup.log` ذخیره می‌شود.
