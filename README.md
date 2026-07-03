# آموزش نصب و راه‌اندازی ZZPanelBot با داکر

این ریپازیتوری اکنون روی ساختار **Docker Compose** اجرا می‌شود. تمام مراحل نصب، به‌روزرسانی، بکاپ و بازیابی با داکر انجام می‌شود.

---

## روش اول: نصب خودکار با اسکریپت zzbot (توصیه شده)

ساده‌ترین راه برای نصب و مدیریت پروژه، استفاده از اسکریپت مدیریت خودکار است. این اسکریپت پیش‌نیازها را بررسی و نصب می‌کند، آخرین نسخه را در مسیر `/srv` مستقر می‌کند و دستور سراسری `zzbot` را جهت مدیریت آسان برای شما ثبت می‌نماید.

برای شروع، دستور زیر را در سرور خود اجرا کنید:

```bash
bash <(curl -Ls "https://raw.githubusercontent.com/gitma99/zz-p/main/setup.sh?t=$(date +%s)" )
```

پس از اولین نصب، می‌توانید در هر زمان و در هر فولدری به سادگی با تایپ دستور زیر به منوی مدیریتی دسترسی داشته باشید:
```bash
zzbot
```

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

- یک فایل پیکربندی محیط بسازید یا از نمونه استفاده کنید:
   ```bash
   cp .env.example .env
   ```
- متغیرهای مهم که باید بررسی و مقداردهی کنید:
   - `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` — تنظیمات دیتابیس
   - `BOT_TOKEN`, `CHAT_ID` — توکن و chat id ربات
   - `BACKUP_TELEGRAM_TOKEN`, `BACKUP_CHAT_ID` —  برای ارسال بکاپ به بات/چت متفاوت

---

## نصب و راه‌اندازی

1. از مسیر ریشه پروژه کار کنید.
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
 chmod +x docker/nginx/init-letsencrypt.sh
 bash docker/nginx/init-letsencrypt.sh
 ```

6. سپس سرویس‌ها را بالا بیاورید:
   ```bash
   docker compose up -d
   ```



## بروزرسانی

اگر image جدیدی ساخته یا منتشر کردید، کافی است:

```bash
docker compose pull app
docker compose up -d app
```

## بکاپ خودکار

بکاپ‌ها توسط سرویس `zzpanel_backup` در Docker Compose انجام می‌شوند. خروجی بکاپ‌ها روی میزبان در `docker/backups/` قرار می‌گیرد.

- بکاپ اولیه بلافاصله پس از اجرای کانتینر ساخته می‌شود
- سپس هر روز در نیمه شب تهران یک بکاپ جدید ایجاد می‌شود

### تنظیمات تلگرام برای بکاپ

در فایل `.env` می‌توانید متغیرهای زیر را اضافه کنید تا نوتیفیکیشن بکاپ با بات یا چت متفاوت ارسال شود:

```env
BACKUP_TELEGRAM_TOKEN=your_telegram_bot_token
BACKUP_CHAT_ID=your_telegram_admin_chat_id
```


## بکاپ دستی

برای اجرا از روی میزبان:

```bash
./docker/scripts/docker_backup.sh
```


## بازیابی بکاپ

قبل از اجرای بازیابی، فایل بکاپ ZIP خود را روی میزبان در دایرکتوری `docker/backups/` قرار دهید.

### بازیابی با اسکریپت میزبان

1. از مسیر ریشه پروژه اجرا کنید:
   ```bash
   ./docker/scripts/docker_backup.sh
   ```
2. گزینه `2` یا `Restore` را انتخاب کنید.
3. فایل بکاپ مورد نظر را انتخاب و تأیید کنید.

> اسکریپت بازیابی در صورت انتخاب `y` به صورت خودکار برنامه را ری‌استارت می‌کند،.

## لاگ‌ها و رفع مشکل

برای مشاهده لاگ زنده:

```bash
docker compose logs -f
```

یا فقط لاگ‌های سرویس خاص:

```bash
docker compose logs -f app
docker compose logs -f nginx
docker compose logs -f backup
```

لاگ بکاپ خودکار نیز روی میزبان در `docker/backups/backup.log` ذخیره می‌شود.
