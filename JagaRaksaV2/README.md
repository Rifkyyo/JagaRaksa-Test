# JagaRaksa – Sistem Pengaduan Fasilitas Publik

PHP (7.4+, disarankan 8.1+) + MySQL/MariaDB. Tampilan mengikuti desain Stitch JagaRaksa.

## Instalasi (XAMPP / Laragon / hosting PHP) – hanya 4 langkah
1. Salin folder `jagaraksa` ke `htdocs` (XAMPP) atau `www` (Laragon). Nyalakan Apache & MySQL.
2. Impor database: phpMyAdmin → **Import** → pilih `database.sql` → **Go**
   (atau `mysql -u root -p < database.sql`). File ini membuat database, semua tabel, data master,
   **akun demo (sudah ter-hash)** dan laporan contoh. Aman diimpor ulang kapan saja (mereset semuanya).
3. Cek `config.php` bagian pengaturan DB (default: host `localhost`, user `root`, sandi kosong, db `jagaraksa`).
4. Buka `http://localhost/jagaraksa/`.

**Tidak ada lagi `install.php`.** Akun demo langsung tersedia setelah impor SQL.

## Akun demo
| Peran    | Username   | Kata sandi   |
|----------|------------|--------------|
| Admin    | admin      | Admin123!    |
| Petugas  | petugas    | Petugas123!  |
| Petugas  | petugas2   | Petugas123!  |
| Warga    | warga      | Warga123!    |
| Warga    | siti       | Warga123!    |

Login bisa memakai **username atau email** (huruf besar/kecil tidak berpengaruh).

## Jika login masih gagal
Buka `http://localhost/jagaraksa/diagnosa.php`. Halaman ini memeriksa versi PHP/ekstensi, penyimpanan session,
koneksi database, tabel, daftar akun, menguji login langkah demi langkah, dan punya tombol
**"Perbaiki / buat akun demo"** yang membuat ulang hash kata sandi langsung dari PHP Anda.
Hapus `diagnosa.php` (atau set `APP_DEBUG = false`) sebelum dipasang di server publik.

## Alur akses
- **Tamu:** hanya Beranda dan halaman login/daftar; halaman lain dialihkan ke login.
- **Daftar / login berhasil:** langsung ke **Dashboard**.
- **Warga:** Dashboard, Peta, Laporan Saya. **Petugas:** + Kelola Laporan. **Admin:** + Kategori & Wilayah, Pengguna.
- Pendaftaran publik selalu berperan warga; akun petugas/admin dibuat admin di menu Pengguna.

## Keamanan
Prepared statements, `password_hash` bcrypt, token CSRF, escape output, regenerasi ID session saat login,
pembatas percobaan login berbasis database (8 gagal / 10 menit per akun+IP), validasi isi file upload,
`.htaccess` di `uploads/` dan `storage/`.
