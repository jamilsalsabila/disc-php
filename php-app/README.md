# DISC PHP (SQLite)

Versi produksi aplikasi asesmen DISC berbasis PHP + SQLite.

## Fitur utama

- Tes DISC one-for-all (Most/Least) dengan timer.
- Autosave jawaban parsial kandidat.
- Auto-finalize kandidat timeout saat ada request admin/kandidat (tanpa cron wajib).
- Dashboard HR (SPA/filter async, chart, profil kandidat).
- CRUD soal manual.
- Bulk upload soal via CSV:
  - download template,
  - preview sebelum import,
  - mode `append` / `replace semua soal`,
  - validasi duplikasi `order`,
  - export error report CSV.
- Export data kandidat dan jawaban.

## Aturan bank soal

- Bank soal aktif saat ini berjalan dengan scope `Role: All` (one-for-all).
- Mapping DISC disimpan per soal (`disc_a`, `disc_b`, `disc_c`, `disc_d`) dan dipakai langsung saat scoring.

## Aturan scoring ringkas

- Skor DISC raw dihitung dari:
  - `Most = +2`
  - `Least = -1`
- Evaluasi rekomendasi utama memakai **role yang dipilih kandidat**.
- Red flag reject berlaku per role. Contoh:
  - Manager: `D < 12` atau `I < 12`.
  - Service/Bar: `I < 12`.
  - Admin/Kitchen: `C < 12`.
  - Support: `S < 12`.
- Jika kena red flag reject atau skor role dipilih di bawah batas minimum, hasil: `TIDAK_DIREKOMENDASIKAN`.

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
- `HR_LOGIN_EMAIL`, `HR_PASSWORD_HASH`.

## Login HR default

- Email: `hr@disc.local`
- Password: gunakan hash default (lihat `.env.example`) atau ganti hash bcrypt di `.env`.

## Catatan deploy

- Document root harus ke folder `public`.
- PHP extension `pdo_sqlite` wajib aktif.
- Folder `storage/` harus writable oleh web server.
- Jangan overwrite file database produksi: `storage/disc_app.sqlite`.
