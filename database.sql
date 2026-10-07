-- ============================================================
-- DATABASE: bebas_pustaka
-- Sistem Informasi Surat Keterangan Bebas Pustaka
-- ============================================================

CREATE DATABASE IF NOT EXISTS bebas_pustaka
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE bebas_pustaka;

-- ============================================================
-- TABLE: jurusan
-- ============================================================
CREATE TABLE IF NOT EXISTS jurusan (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nama_jurusan VARCHAR(100) NOT NULL,
  fakultas    VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: petugas
-- ============================================================
CREATE TABLE IF NOT EXISTS petugas (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  nip      VARCHAR(20)  NOT NULL UNIQUE,
  nama     VARCHAR(100) NOT NULL,
  email    VARCHAR(100) NOT NULL UNIQUE,
  jabatan  VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: mahasiswa
-- ============================================================
CREATE TABLE IF NOT EXISTS mahasiswa (
  id         INT AUTO_INCREMENT UNIQUE,
  nim        VARCHAR(20)  PRIMARY KEY,
  nama       VARCHAR(100) NOT NULL,
  email      VARCHAR(100) NOT NULL UNIQUE,
  no_telepon VARCHAR(20),
  id_jurusan INT          NOT NULL,
  angkatan   INT,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_jurusan) REFERENCES jurusan(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: riwayat_pinjaman
-- ============================================================
CREATE TABLE IF NOT EXISTS riwayat_pinjaman (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  nim                  VARCHAR(20) NOT NULL,
  buku_dipinjam        INT         DEFAULT 0,
  tunggakan_denda      DECIMAL(10,2) DEFAULT 0.00,
  status_bebas_tanggungan BOOLEAN  DEFAULT FALSE,
  updated_at           TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (nim) REFERENCES mahasiswa(nim) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: penyerahan_ta
-- ============================================================
CREATE TABLE IF NOT EXISTS penyerahan_ta (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  nim              VARCHAR(20) NOT NULL,
  judul_ta         TEXT        NOT NULL,
  file_abstrak     VARCHAR(255),
  file_fulltext    VARCHAR(255),
  status_verifikasi ENUM('menunggu', 'diverifikasi', 'ditolak') DEFAULT 'menunggu',
  catatan_revisi   TEXT,
  tgl_penyerahan   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (nim) REFERENCES mahasiswa(nim) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: pengajuan_bebas_pustaka
-- ============================================================
CREATE TABLE IF NOT EXISTS pengajuan_bebas_pustaka (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nim             VARCHAR(20) NOT NULL,
  tgl_pengajuan   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  status          ENUM('menunggu', 'diproses', 'disetujui', 'ditolak') DEFAULT 'menunggu',
  alasan_penolakan TEXT,
  created_at      TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (nim) REFERENCES mahasiswa(nim) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: surat_bebas_pustaka
-- ============================================================
CREATE TABLE IF NOT EXISTS surat_bebas_pustaka (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_pengajuan  INT          NOT NULL,
  id_petugas    INT          NOT NULL,
  nomor_surat   VARCHAR(100) NOT NULL UNIQUE,
  tgl_terbit    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  file_pdf      VARCHAR(255),
  file_ttd      VARCHAR(255),
  FOREIGN KEY (id_pengajuan) REFERENCES pengajuan_bebas_pustaka(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  FOREIGN KEY (id_petugas)   REFERENCES petugas(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: notifikasi_mahasiswa
-- Pesan dari petugas perpustakaan ke mahasiswa
-- ============================================================
CREATE TABLE IF NOT EXISTS notifikasi_mahasiswa (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nim           VARCHAR(20) NOT NULL,
  id_pengajuan  INT         NULL,
  id_petugas    INT         NULL,
  judul         VARCHAR(200) NULL,
  pesan         TEXT         NOT NULL,
  jenis         ENUM('info','warning','success','error') DEFAULT 'info',
  sudah_dibaca  TINYINT(1)   DEFAULT 0,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (nim) REFERENCES mahasiswa(nim) ON UPDATE CASCADE ON DELETE CASCADE,
  FOREIGN KEY (id_pengajuan) REFERENCES pengajuan_bebas_pustaka(id) ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (id_petugas)   REFERENCES petugas(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: activity_logs
-- Log aktivitas sistem untuk monitoring superadmin
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_logs (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  id_petugas   INT          NULL,
  nama_petugas VARCHAR(150) NULL,
  role         VARCHAR(30)  NOT NULL DEFAULT 'admin',
  aksi         VARCHAR(100) NOT NULL,
  detail       TEXT         NULL,
  ip_address   VARCHAR(50)  NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_role        (role),
  INDEX idx_created_at  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- SEEDER DATA
-- ============================================================

INSERT INTO jurusan (nama_jurusan, fakultas) VALUES
('Teknik Informatika',     'Fakultas Teknik'),
('Sistem Informasi',       'Fakultas Teknik'),
('Teknik Elektro',         'Fakultas Teknik'),
('Manajemen',              'Fakultas Ekonomi'),
('Akuntansi',              'Fakultas Ekonomi'),
('Hukum',                  'Fakultas Hukum');

INSERT INTO petugas (nip, nama, email, jabatan) VALUES
('196501011990031001', 'Budi Santoso',   'budi@perpustakaan.ac.id',   'Kepala Perpustakaan'),
('197203152000121002', 'Sari Dewi',      'sari@perpustakaan.ac.id',   'Pustakawan'),
('198506202010011003', 'Andi Pratama',   'andi@perpustakaan.ac.id',   'Staf Sirkulasi');

INSERT INTO mahasiswa (nim, nama, email, no_telepon, id_jurusan, angkatan) VALUES
('2021001001', 'Ahmad Fauzi',        'ahmad.fauzi@student.ac.id',   '081234567890', 1, 2021),
('2021001002', 'Siti Aminah',        'siti.aminah@student.ac.id',   '082345678901', 1, 2021),
('2021002001', 'Reza Mahendra',      'reza.mahendra@student.ac.id', '083456789012', 2, 2021),
('2020001001', 'Dina Rahayu',        'dina.rahayu@student.ac.id',   '084567890123', 1, 2020),
('2020002001', 'Bima Sakti',         'bima.sakti@student.ac.id',    '085678901234', 2, 2020),
('2020003001', 'Nurul Hidayah',      'nurul.h@student.ac.id',       '086789012345', 3, 2020),
('2019001001', 'Fajar Ramadhan',     'fajar.r@student.ac.id',       '087890123456', 1, 2019),
('2019004001', 'Maya Indah Sari',    'maya.indah@student.ac.id',    '088901234567', 4, 2019);

INSERT INTO riwayat_pinjaman (nim, buku_dipinjam, tunggakan_denda, status_bebas_tanggungan) VALUES
('2021001001', 2,  0.00,    FALSE),
('2021001002', 0,  0.00,    TRUE),
('2021002001', 1,  5000.00, FALSE),
('2020001001', 0,  0.00,    TRUE),
('2020002001', 3,  15000.00,FALSE),
('2020003001', 0,  0.00,    TRUE),
('2019001001', 0,  0.00,    TRUE),
('2019004001', 1,  0.00,    FALSE);

INSERT INTO penyerahan_ta (nim, judul_ta, file_abstrak, file_fulltext, status_verifikasi, catatan_revisi) VALUES
('2020001001', 'Sistem Informasi Manajemen Perpustakaan Berbasis Web', 'abstrak_dina_2020001001.pdf', 'fulltext_dina_2020001001.pdf', 'diverifikasi', NULL),
('2020003001', 'Analisis Kinerja Jaringan Komputer pada Kampus XYZ',  'abstrak_nurul_2020003001.pdf','fulltext_nurul_2020003001.pdf','diverifikasi', NULL),
('2019001001', 'Implementasi Machine Learning untuk Deteksi Spam Email','abstrak_fajar_2019001001.pdf','fulltext_fajar_2019001001.pdf','diverifikasi', NULL),
('2021001002', 'Pengembangan Aplikasi Mobile untuk Monitoring Kesehatan','abstrak_siti_2021001002.pdf', NULL, 'menunggu', NULL),
('2021002001', 'Sistem Pakar Diagnosa Penyakit Tanaman dengan Metode Fuzzy', NULL, NULL, 'menunggu', NULL);

INSERT INTO pengajuan_bebas_pustaka (nim, tgl_pengajuan, status, alasan_penolakan) VALUES
('2020001001', '2024-11-01 09:00:00', 'disetujui', NULL),
('2020003001', '2024-11-05 10:30:00', 'disetujui', NULL),
('2019001001', '2024-10-20 08:15:00', 'disetujui', NULL),
('2021001002', '2024-12-01 11:00:00', 'menunggu',  NULL),
('2021002001', '2024-12-02 14:00:00', 'menunggu',  NULL),
('2019004001', '2024-11-28 09:30:00', 'ditolak',   'Masih terdapat buku yang belum dikembalikan');

INSERT INTO surat_bebas_pustaka (id_pengajuan, id_petugas, nomor_surat, tgl_terbit, file_pdf) VALUES
(1, 2, 'BP/2024/001/XI', '2024-11-03 10:00:00', 'surat_BP_2024_001.pdf'),
(2, 2, 'BP/2024/002/XI', '2024-11-07 09:30:00', 'surat_BP_2024_002.pdf'),
(3, 1, 'BP/2024/003/X',  '2024-10-22 08:00:00', 'surat_BP_2024_003.pdf');
