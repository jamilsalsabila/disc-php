# DISC PHP (SQLite)

Versi produksi aplikasi asesmen DISC berbasis PHP + SQLite.

## Fitur utama

- Tes DISC per role (Most/Least) dengan timer.
- Autosave jawaban parsial kandidat.
- Auto-timeout submit untuk kandidat yang melewati deadline.
- Dashboard HR (SPA/filter async, chart, profil kandidat).
- CRUD soal manual.
- Bulk upload soal via CSV:
  - download template,
  - preview sebelum import,
  - mode `append` / `replace per role`,
  - validasi duplikasi `role + order`,
  - export error report CSV.
- Export data kandidat dan jawaban.

## Jalankan lokal

1. Copy env:
   - `cp .env.example .env`
2. Jalankan server:
   - `php -S 127.0.0.1:8080 -t public`
3. Buka:
   - `http://127.0.0.1:8080`

## Konfigurasi `.env` penting

- `APP_BASE_PATH` untuk deploy subfolder (contoh: `/disc`).
- `AUTO_SEED_QUESTIONS=false` (disarankan; soal dikelola dari dashboard/DB).
- `TEST_DURATION_MINUTES`, `MIN_COMPLETION_RATIO`.
- `TIMEOUT_SWEEP_EVERY_SECONDS`, `TIMEOUT_SWEEP_LIMIT`.
- `HR_LOGIN_EMAIL`, `HR_PASSWORD_HASH`.

## Login HR default

- Email: `hr@disc.local`
- Password: gunakan hash default (lihat `.env.example`) atau ganti hash bcrypt di `.env`.

## Catatan deploy

- Document root harus ke folder `public`.
- PHP extension `pdo_sqlite` wajib aktif.
- Folder `storage/` harus writable oleh web server.
- Jangan overwrite file database produksi: `storage/disc_app.sqlite`.
