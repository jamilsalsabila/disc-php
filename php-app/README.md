# DISC PHP (SQLite)

Versi PHP + SQLite dari aplikasi DISC.

## Jalankan lokal

1. Copy env:
   - `cp .env.example .env`
2. Jalankan server:
   - `php -S 127.0.0.1:8080 -t public`
3. Buka:
   - `http://127.0.0.1:8080`

## Login HR default

- Email: `hr@disc.local`
- Password: gunakan hash default (cek `.env.example`) atau ganti hash bcrypt di `.env`.

## Catatan deploy

- Document root harus diarahkan ke folder `public`.
- Pastikan extension PHP `pdo_sqlite` aktif.
- Folder `storage/` harus writable oleh web server.
- Jika app dipasang di subfolder (misal `/disc-php/public`), set `APP_BASE_PATH`.
