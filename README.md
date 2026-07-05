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
* **1) Install ZZPanel**: راه‌اندازی و نصب کامل پروژه (تنها برای اولین بار یا نصب مجدد).
* **2) Install / Update zzbot Menu**: نصب یا به‌روزرسانی منوی مدیریت `zzbot`.
* **3) Edit .env**: باز کردن سریع فایل تنظیمات محیطی ربات جهت ویرایش متغیرها.
* **4) Initialize SSL (Let's Encrypt)**: راه‌اندازی و تمدید گواهی SSL برای امنیت اتصالات دامنه.
* **5) Start Services**: روشن کردن کانتینرهای پروژه داکر در پس‌زمینه.
* **6) Stop Services**: خاموش کردن و متوقف کردن کانتینرها.
* **7) Restart Services**: راه‌اندازی مجدد سرویس‌های پروژه بدون تغییر در وضعیت کانتینرها.
* **8) Update Services**: دریافت آخرین نسخه‌های پایدار ایمیج‌ها و اعمال بروزرسانی روی کانتینرها.
* **9) Uninstall ZZPanel**: پاک کردن کامل تمامی کانتینرها، ایمیج‌ها، دیتابیس و حذف دستور `zzbot`.
* **10) Backup Database & Files**: ایجاد فایل پشتیبان دستی از دیتابیس و فایل‌های کلیدی.
* **11) Restore Database & Files**: بازیابی اطلاعات دیتابیس از روی فایل‌های بکاپ موجود.
* **12) View Live Logs**: مشاهده لاگ‌های زنده تمامی سرویس‌ها به صورت همزمان (خروج با کلیدهای ترکیبی `Ctrl+C`).
* **13) View Live Logs for Specific Service**: مشاهده لاگ‌های زنده یک سرویس خاص (مانند `app`, `nginx`, یا `backup`).

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
1. ابتدا از قسمت ریلیز آخرین فایل را دانلود کنید.
2. فایل را در داخل سرور قرار دهید.

3. با دستور زیر فایل را اکسترکت کنید
```bash
unzip zzpanelbot-docker.zip
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
| **BACKUP_RETENTION_DAYS** | تعداد روز نگهداری فایل‌های بکاپ | `14` | ❌ خیر (پیش‌فرض: 14) |

**توضیحات اضافی:**
- `BACKUP_TELEGRAM_TOKEN` و `BACKUP_CHAT_ID`: اگر تنظیم نشوند، سیستم از `BOT_TOKEN` و `CHAT_ID` استفاده می‌کند
- `BACKUP_RETENTION_DAYS`: اگر تنظیم نشود، به طور پیش‌فرض 14 روز در نظر گرفته می‌شود و یک هشدار ثبت می‌شود

---

## نصب و راه‌اندازی دستی
1. دانلود آخرین نسخه ریلیز از گیت‌هاب و اکسترکت آن در مسیر `/srv` یا مسیر دلخواه شما.

2. یک فایل `.env` بسازید یا از `.env.example` کپی کنید:
    ```bash
    cp .env.example .env
    ```
3. اگر نیاز به دسترسی به image از GitHub Container Registry دارید، ابتدا وارد شوید:
   ```bash
   echo "YOUR_GH_TOKEN" | docker login ghcr.io -u YOUR_GITHUB_USERNAME --password-stdin
   ```

   - `YOUR_GH_TOKEN` باید یک GitHub access token باشد که مجوز `write:packages` یا `read:packages` دارد.
   - اگر image عمومی است، نیازی به ورود وجود ندارد.

4. از image پیش‌ساخته استفاده کنید:
   ```bash
   docker compose pull
   ```

5. برای نصب و راه‌اندازی، باید گواهی Let's Encrypt را قبل از اجرای `docker compose up -d` بوت‌استرپ کنید:
 ```bash
 chmod +x /srv/docker/nginx/init-letsencrypt.sh
 bash /srv/docker/nginx/init-letsencrypt.sh
 ```

6. سپس سرویس‌ها را بالا بیاورید:
   ```bash
   docker compose up -d
   ```



## بروزرسانی دستی با Docker Compose

اگر image جدیدی ساخته یا منتشر کردید، کافی است:

```bash
docker compose pull app
docker compose up -d app
```

## بکاپ خودکار

بکاپ‌ها توسط سرویس `zzpanel_backup` در Docker Compose انجام می‌شوند. خروجی بکاپ‌ها روی میزبان در `data/backups/` قرار می‌گیرد.

- بکاپ اولیه بلافاصله پس از اجرای کانتینر ساخته می‌شود
- سپس هر روز در نیمه شب تهران یک بکاپ جدید ایجاد می‌شود

### تنظیمات تلگرام برای بکاپ

در فایل `.env` می‌توانید متغیرهای زیر را اضافه کنید تا نوتیفیکیشن بکاپ با بات یا چت متفاوت ارسال شود:

```env
BACKUP_TELEGRAM_TOKEN=your_telegram_bot_token
BACKUP_CHAT_ID=your_telegram_admin_chat_id
```


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
