# DISC PHP (SQLite)

Versi produksi aplikasi asesmen DISC berbasis PHP + SQLite.

## Fitur utama

- Tes 2 tahap: DISC one-for-all (Most/Least) lalu Esai, dengan timer terpisah.
- Autosave jawaban parsial kandidat.
- Auto-finalize kandidat timeout saat ada request admin/kandidat (tanpa cron wajib).
- Dashboard HR (SPA/filter async, chart, profil kandidat).
- Template checklist wawancara HR per kandidat (tersimpan, editable).
- Integritas tes versi ringan: deteksi `tab switch` dan `paste` selama tes (indikator risiko untuk HR).
- Integritas tes versi lanjutan:
  - event timeline kandidat (phase events),
  - typing pattern metrics untuk jawaban esai (keystroke/input/paste/active time).
- Integrasi evaluasi AI (opsional):
  - tombol `Generate/Regenerate Analisis AI` di profil kandidat,
  - menyimpan skor AI, konklusi, saran posisi, strengths/risks/follow-up.
- CRUD soal manual.
- CRUD bank soal esai (manual) untuk persiapan asesmen tulisan.
  - Kelompok soal esai: `Manager`, `Back office`, `Kitchen`, `Bar`, `Floor`.
  - Pada form tambah soal esai, `Urutan Soal` otomatis menyesuaikan urutan berikutnya berdasarkan kelompok role yang dipilih.
  - Halaman `Kelola Soal Esai` mendukung pagination (`10/20/50/100`) dan sort (default, urutan, kelompok, status, update, id).
- Bulk upload soal via CSV:
  - download template,
  - preview sebelum import,
  - mode `append` / `replace`,
  - validasi duplikasi `order`,
  - export error report CSV.
  - tersedia untuk `Kelola Soal DISC` dan `Kelola Soal Esai`.
  - khusus soal esai: teks `\n` di CSV otomatis dikonversi menjadi baris baru saat import.
- Export data kandidat dan jawaban.
  - Export per kandidat (CSV/PDF) mencakup: ringkasan, jawaban DISC, jawaban esai, event timeline, typing metrics, dan catatan HR.
- UI admin lebih rapih dan konsisten:
  - mode tabel `Compact/Normal` (preferensi tersimpan di browser),
  - tabel `Kelola Soal DISC`, `Kelola Soal Esai`, dashboard, dan profil kandidat lebih compact,
  - tabel kandidat di dashboard sudah pagination async (`10/20/50/100` baris per halaman),
  - tombol aksi diseragamkan ukurannya,
  - teks tabel yang panjang otomatis dipersingkat (ellipsis) dan tampil full saat hover tooltip,
  - tabel Event Timeline di profil kandidat dirapikan agar lebih stabil di desktop/mobile,
  - inline style di view HR dipindah ke CSS terpusat.

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

## Endpoint Kandidat (ringkas)

- `GET /disc-test` untuk tes DISC.
- `POST /disc-submit` untuk submit fase DISC.
- `GET /essay-test` untuk tes esai.
- `POST /essay-submit` untuk submit final asesmen.
- Endpoint kompatibilitas lama (`/test`, `/submit`) masih diterima agar transisi aman.

## Konfigurasi `.env` penting

- `APP_BASE_PATH` untuk deploy subfolder (contoh: `/disc`).
- `AUTO_SEED_QUESTIONS=false` (disarankan; soal dikelola dari dashboard/DB).
- `TEST_DURATION_MINUTES`, `ESSAY_DURATION_MINUTES`, `MIN_COMPLETION_RATIO`.
- `HR_LOGIN_EMAIL`, `HR_PASSWORD_HASH`.
- Untuk AI evaluation:
  - `AI_EVALUATION_ENABLED=true`
  - `OPENAI_API_KEY=...`
  - `OPENAI_MODEL=gpt-5.4`
  - `OPENAI_TIMEOUT_SECONDS=60`
  - `OPENAI_MAX_RETRIES=2`

## Login HR default

- Email: `hr@disc.local`
- Password: gunakan hash default (lihat `.env.example`) atau ganti hash bcrypt di `.env`.

## Catatan deploy

- Document root harus ke folder `public`.
- PHP extension `pdo_sqlite` wajib aktif.
- Folder `storage/` harus writable oleh web server.
- Jangan overwrite file database produksi: `storage/disc_app.sqlite`.

## File upload aman (tanpa ganggu data kandidat)

Untuk update fitur/tampilan tanpa merusak data kandidat lama, upload/replace:

- `public/`
- `views/`
- `app/` (kecuali file env lokal)
- `assets/` (jika dipakai pada setup hosting Anda)

Jangan overwrite:

- `storage/disc_app.sqlite` (database produksi)
- `.env` produksi
