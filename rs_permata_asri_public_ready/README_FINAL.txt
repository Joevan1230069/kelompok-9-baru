RUMAH SAKIT PERMATA ASRI - SIMRS FINAL

CARA PASANG DI XAMPP
1. Extract folder rs_permata_asri_baru.
2. Letakkan folder di dalam htdocs XAMPP.
   Contoh: C:\xampp\htdocs\rs_permata_asri_baru
3. Start Apache dan MySQL dari XAMPP Control Panel.
4. Jika database simrs_permata_asri BELUM ADA:
   buka http://localhost/rs_permata_asri_baru/install_database.php
   lalu klik Buat / Reset Database.
5. Jika database versi lama SUDAH ADA:
   jangan reset database. Buka
   http://localhost/rs_permata_asri_baru/update_database.php
   lalu klik Jalankan Update Final.
6. Cek koneksi:
   http://localhost/rs_permata_asri_baru/setup_check.php
7. Buka aplikasi:
   http://localhost/rs_permata_asri_baru/

FITUR UTAMA
- Login individual dan RBAC server-side.
- Registrasi pasien online dan verifikasi admin/admisi.
- Pemilihan poli, dokter, dan jadwal.
- EMR dengan pembatasan pasien per DPJP.
- Identitas pemeriksa otomatis berdasarkan akun login.
- CPOE: Laboratorium, Radiologi, Obat, Konsultasi, Rawat Inap.
- Hasil Laboratory dan Radiology kembali ke dokter peminta.
- Farmasi dan inventory (stok, expiry date, provider, dispensing).
- Triage IGD.
- Rawat inap dari order dokter.
- Laporan operasional.
- Audit trail.
- Konfirmasi logout.

Daftar akun demo terdapat pada AKUN_LOGIN.txt.
