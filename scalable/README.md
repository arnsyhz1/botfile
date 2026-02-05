# Scalable Mode (Worker + Queue)

Mode ini menambah arsitektur **webhook → queue → worker** tanpa mengubah `bot.php` lama.

## Tujuan
- Bot lama tetap jalan (`bot.php` tetap jadi source logic utama).
- Siap scale horizontal: webhook cepat respon karena hanya enqueue.
- Worker multi process untuk throughput tinggi.
- Retry otomatis dengan exponential backoff.
- Dashboard monitoring queue real-time.

## Struktur
- `scalable/webhook_queue.php` → endpoint Telegram baru (hanya enqueue update).
- `scalable/worker.php` → proses worker single process.
- `scalable/worker_pool.php` → manager untuk jalankan banyak worker sekaligus.
- `scalable/dashboard.php` → dashboard monitoring queue + tombol retry failed job.
- `scalable/src/Queue/DatabaseQueue.php` → adapter queue berbasis MySQL.
- `scalable/src/Worker/LegacyBotWorker.php` → eksekusi `bot.php` lama per job.
- `scalable/config.php` → konfigurasi DB, queue, monitoring, worker pool.

## Cara pakai cepat
1. Arahkan webhook Telegram ke `https://domain-anda/scalable/webhook_queue.php`.
2. Jalankan worker pool di server:
   ```bash
   php scalable/worker_pool.php
   ```
3. Buka dashboard:
   - `https://domain-anda/scalable/dashboard.php`
   - jika set token: `https://domain-anda/scalable/dashboard.php?token=TOKEN`

## Exponential backoff
Jika job gagal, delay retry mengikuti pola:
- attempt 1: `retry_base_seconds`
- attempt 2: `retry_base_seconds * 2`
- attempt 3: `retry_base_seconds * 4`
- dst sampai batas `retry_max_seconds`

## Environment variables (opsional)
- `BOT_DB_HOST`
- `BOT_DB_NAME`
- `BOT_DB_USER`
- `BOT_DB_PASS`
- `BOT_QUEUE_TABLE` (default `bot_jobs`)
- `BOT_WORKER_SLEEP` (default `1`)
- `BOT_JOB_MAX_ATTEMPTS` (default `6`)
- `BOT_JOB_RETRY_BASE` (default `5` detik)
- `BOT_JOB_RETRY_MAX` (default `300` detik)
- `BOT_MONITOR_LIMIT` (default `100`)
- `BOT_MONITOR_TOKEN` (default kosong, disarankan isi untuk keamanan dashboard)
- `BOT_WORKER_POOL_SIZE` (default `4`)
- `BOT_LEGACY_PATH` (default ke `../bot.php`)

## Catatan kompatibilitas
- Tidak ada perubahan pada file `bot.php`.
- Jika ingin rollback, cukup kembalikan webhook Telegram ke endpoint lama (`bot.php`).


## Troubleshooting (bot tidak respon)
1. Pastikan worker aktif (`php scalable/worker.php`) atau pool aktif (`php scalable/worker_pool.php`).
2. Cek dashboard, jika `pending` terus naik artinya worker belum jalan / crash.
3. Jalankan worker di foreground dan lihat log error.
4. Mode queue menjalankan `bot.php` via CLI, jadi `bot.php` sudah mendukung baca payload dari `STDIN` saat `php://input` kosong.
