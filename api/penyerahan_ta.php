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
                'SELECT p.*, m.nama, m.email, j.nama_jurusan
                 FROM penyerahan_ta p
                 JOIN mahasiswa m ON m.nim = p.nim
                 JOIN jurusan j ON j.id = m.id_jurusan
                 WHERE p.id = ?'
            );
            $stmt->execute([$id]);
            $data = $stmt->fetch();
            if (!$data) respond(false, null, 'Data penyerahan TA tidak ditemukan', 404);
            respond(true, $data);
        } else {
            $where  = [];
            $params = [];
            if (!empty($_GET['status'])) {
                $where[]  = 'p.status_verifikasi = ?';
                $params[] = $_GET['status'];
            }
            if (!empty($_GET['nim'])) {
                $where[]  = 'p.nim = ?';
                $params[] = $_GET['nim'];
            }
            $sql = 'SELECT p.*, m.nama, m.email, j.nama_jurusan
                    FROM penyerahan_ta p
                    JOIN mahasiswa m ON m.nim = p.nim
                    JOIN jurusan j ON j.id = m.id_jurusan';
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY p.tgl_penyerahan DESC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            respond(true, $stmt->fetchAll());
        }
        break;

    case 'POST':
        // Submission via multipart/form-data (karena ada file upload)
        $nim      = trim($_POST['nim']      ?? '');
        $judul_ta = trim($_POST['judul_ta'] ?? '');
        if (!$nim || !$judul_ta) respond(false, null, 'nim dan judul_ta wajib diisi', 400);

        // Pastikan mahasiswa ada
        $mhs = $db->prepare('SELECT nim FROM mahasiswa WHERE nim = ?');
        $mhs->execute([$nim]);
        if (!$mhs->fetch()) respond(false, null, 'Mahasiswa tidak ditemukan', 404);

        $upload_dir   = __DIR__ . '/../uploads/ta/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $file_abstrak  = null;
        $file_fulltext = null;

        // Upload abstrak
        if (!empty($_FILES['file_abstrak']['name'])) {
            $ext  = pathinfo($_FILES['file_abstrak']['name'], PATHINFO_EXTENSION);
            $name = 'abstrak_' . $nim . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['file_abstrak']['tmp_name'], $upload_dir . $name);
            $file_abstrak = $name;
        }
        // Upload fulltext
        if (!empty($_FILES['file_fulltext']['name'])) {
            $ext  = pathinfo($_FILES['file_fulltext']['name'], PATHINFO_EXTENSION);
            $name = 'fulltext_' . $nim . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['file_fulltext']['tmp_name'], $upload_dir . $name);
            $file_fulltext = $name;
        }

        $stmt = $db->prepare(
            'INSERT INTO penyerahan_ta (nim, judul_ta, file_abstrak, file_fulltext) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$nim, $judul_ta, $file_abstrak, $file_fulltext]);
        respond(true, ['id' => $db->lastInsertId()], 'Penyerahan TA berhasil disimpan', 201);
        break;

    case 'PUT':
        if (!$id) respond(false, null, 'ID diperlukan', 400);
        $body   = getRequestBody();
        $status = trim($body['status_verifikasi'] ?? '');
        $catatan= trim($body['catatan_revisi']    ?? '');
        $valid  = ['menunggu', 'diverifikasi', 'ditolak'];
        if (!in_array($status, $valid, true)) respond(false, null, 'status_verifikasi tidak valid', 400);

        $stmt = $db->prepare(
            'UPDATE penyerahan_ta SET status_verifikasi=?, catatan_revisi=? WHERE id=?'
        );
        $stmt->execute([$status, $catatan ?: null, $id]);
        if ($stmt->rowCount() === 0) respond(false, null, 'Data tidak ditemukan', 404);
        respond(true, null, 'Status penyerahan TA berhasil diupdate');
        break;

    case 'DELETE':
        if (!$id) respond(false, null, 'ID diperlukan', 400);
        $stmt = $db->prepare('DELETE FROM penyerahan_ta WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) respond(false, null, 'Data tidak ditemukan', 404);
        respond(true, null, 'Data penyerahan TA berhasil dihapus');
        break;

    default:
        respond(false, null, 'Method tidak didukung', 405);
}
