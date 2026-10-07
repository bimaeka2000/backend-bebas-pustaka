<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();
$db     = getDB();
$method = getMethod();
$id     = $_GET['id'] ?? null;

switch ($method) {
    case 'GET':
        if ($id) {
            $stmt = $db->prepare(
                'SELECT pb.*, m.nama, m.email, m.angkatan, j.nama_jurusan, j.fakultas AS nama_fakultas, j.gelar,
                        rp.buku_dipinjam, rp.tunggakan_denda, rp.status_bebas_tanggungan,
                        pt.status_verifikasi AS status_ta,
                        s.nomor_surat, s.tgl_terbit, s.file_pdf, s.file_ttd
                 FROM pengajuan_bebas_pustaka pb
                 JOIN mahasiswa m ON m.nim = pb.nim
                 JOIN jurusan j ON j.id = m.id_jurusan
                 LEFT JOIN riwayat_pinjaman rp ON rp.nim = pb.nim
                 LEFT JOIN penyerahan_ta pt ON pt.nim = pb.nim AND pt.status_verifikasi = "diverifikasi"
                 LEFT JOIN surat_bebas_pustaka s ON s.id_pengajuan = pb.id
                 WHERE pb.id = ?'
            );
            $stmt->execute([$id]);
            $data = $stmt->fetch();
            if (!$data) respond(false, null, 'Pengajuan tidak ditemukan', 404);
            respond(true, $data);
        } else {
            $where  = [];
            $params = [];
            if (!empty($_GET['status'])) {
                $where[]  = 'pb.status = ?';
                $params[] = $_GET['status'];
            }
            if (!empty($_GET['nim'])) {
                $where[]  = 'pb.nim = ?';
                $params[] = $_GET['nim'];
            }
            $sql = 'SELECT pb.*, m.nama, m.email, m.angkatan, j.nama_jurusan, j.fakultas AS nama_fakultas, j.gelar,
                           rp.buku_dipinjam, rp.tunggakan_denda, rp.status_bebas_tanggungan,
                           s.nomor_surat, s.file_ttd
                    FROM pengajuan_bebas_pustaka pb
                    JOIN mahasiswa m ON m.nim = pb.nim
                    JOIN jurusan j ON j.id = m.id_jurusan
                    LEFT JOIN riwayat_pinjaman rp ON rp.nim = pb.nim
                    LEFT JOIN surat_bebas_pustaka s ON s.id_pengajuan = pb.id';
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY pb.tgl_pengajuan DESC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            respond(true, $stmt->fetchAll());
        }
        break;

    case 'POST':
        $body = getRequestBody();
        $nim  = trim($body['nim'] ?? '');
        if (!$nim) respond(false, null, 'nim wajib diisi', 400);

        // Cek mahasiswa ada
        $mhs = $db->prepare('SELECT nim FROM mahasiswa WHERE nim = ?');
        $mhs->execute([$nim]);
        if (!$mhs->fetch()) respond(false, null, 'Mahasiswa tidak ditemukan', 404);

        // Cek apakah ada pengajuan aktif yang belum selesai
        $cek = $db->prepare(
            "SELECT id FROM pengajuan_bebas_pustaka WHERE nim = ? AND status IN ('menunggu','diproses')"
        );
        $cek->execute([$nim]);
        if ($cek->fetch()) respond(false, null, 'Masih ada pengajuan yang sedang diproses', 409);

        $stmt = $db->prepare('INSERT INTO pengajuan_bebas_pustaka (nim) VALUES (?)');
        $stmt->execute([$nim]);
        respond(true, ['id' => $db->lastInsertId()], 'Pengajuan berhasil diajukan', 201);
        break;

    case 'PUT':
        // Update status pengajuan
        if (!$id) respond(false, null, 'ID pengajuan diperlukan', 400);
        $body   = getRequestBody();
        $status = trim($body['status'] ?? '');
        $alasan = trim($body['alasan_penolakan'] ?? '');
        $valid  = ['menunggu', 'diproses', 'disetujui', 'ditolak'];
        if (!in_array($status, $valid, true)) respond(false, null, 'Status tidak valid', 400);
        if ($status === 'ditolak' && !$alasan) respond(false, null, 'alasan_penolakan wajib diisi saat menolak', 400);

        $stmt = $db->prepare(
            'UPDATE pengajuan_bebas_pustaka SET status=?, alasan_penolakan=? WHERE id=?'
        );
        $stmt->execute([$status, $alasan ?: null, $id]);
        if ($stmt->rowCount() === 0) respond(false, null, 'Pengajuan tidak ditemukan', 404);
        respond(true, null, 'Status pengajuan berhasil diupdate');
        break;

    case 'DELETE':
        if (!$id) respond(false, null, 'ID diperlukan', 400);
        try {
            // Hapus notifikasi terkait pengajuan ini terlebih dahulu
            $delNotif = $db->prepare('DELETE FROM notifikasi_mahasiswa WHERE id_pengajuan = ?');
            $delNotif->execute([$id]);

            $stmt = $db->prepare('DELETE FROM pengajuan_bebas_pustaka WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) respond(false, null, 'Pengajuan tidak ditemukan', 404);
            respond(true, null, 'Pengajuan berhasil dihapus');
        } catch (PDOException $e) {
            respond(false, null, 'Tidak dapat menghapus pengajuan yang sudah memiliki surat', 409);
        }
        break;

    default:
        respond(false, null, 'Method tidak didukung', 405);
}
