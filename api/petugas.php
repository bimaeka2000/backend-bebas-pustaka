<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();
$db     = getDB();
$method = getMethod();
$id     = $_GET['id'] ?? null;
$action = $_GET['action'] ?? null;

// Pastikan kolom gelar_depan, gelar_belakang, dan foto ada di tabel petugas
try {
    $db->exec("ALTER TABLE petugas ADD COLUMN role VARCHAR(20) DEFAULT 'admin'");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE petugas ADD COLUMN password VARCHAR(255) NULL");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE petugas ADD COLUMN gelar_depan VARCHAR(50) NULL");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE petugas ADD COLUMN gelar_belakang VARCHAR(50) NULL");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE petugas ADD COLUMN foto VARCHAR(255) NULL");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE petugas ADD COLUMN ttd VARCHAR(255) NULL");
} catch (Exception $e) {}

function formatNamaGelar($nama, $depan, $belakang) {
    $res = trim($nama ?? '');
    if (!empty($depan)) {
        $res = trim($depan) . ' ' . $res;
    }
    if (!empty($belakang)) {
        $res = $res . ', ' . trim($belakang);
    }
    return $res;
}

switch ($method) {
    case 'GET':
        if ($id) {
            $stmt = $db->prepare('SELECT id, nip, nama, email, jabatan, COALESCE(role, "admin") AS role, gelar_depan, gelar_belakang, foto, ttd FROM petugas WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) respond(false, null, 'Petugas tidak ditemukan', 404);
            $row['nama_lengkap'] = formatNamaGelar($row['nama'], $row['gelar_depan'], $row['gelar_belakang']);
            respond(true, $row);
        } else {
            $stmt = $db->query('SELECT id, nip, nama, email, jabatan, COALESCE(role, "admin") AS role, gelar_depan, gelar_belakang, foto, ttd FROM petugas ORDER BY nama');
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['nama_lengkap'] = formatNamaGelar($r['nama'], $r['gelar_depan'], $r['gelar_belakang']);
            }
            respond(true, $rows);
        }
        break;

    case 'POST':
        // Menangani update profile atau pembuatan petugas baru
        $upload_dir = __DIR__ . '/../uploads/petugas/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0 && empty($_POST) && empty($_FILES) && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false) {
            respond(false, null, 'Upload foto melebihi batas ukuran server', 413);
        }

        // Jika request multipart/form-data
        $isMultipart = !empty($_FILES) || !empty($_POST);
        $body = $isMultipart ? $_POST : getRequestBody();

        $target_id      = $id ?: ($body['id'] ?? null);
        $nip            = trim($body['nip'] ?? '');
        $nama           = trim($body['nama'] ?? '');
        $gelar_depan    = trim($body['gelar_depan'] ?? '');
        $gelar_belakang = trim($body['gelar_belakang'] ?? '');
        $email          = trim($body['email'] ?? '');
        $jabatan        = trim($body['jabatan'] ?? '');
        $role_val       = trim($body['role'] ?? 'admin');
        $pass_val       = trim($body['password'] ?? '');

        // Upload foto jika ada file foto
        $fotoFilename = null;
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['foto'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'Ukuran foto melebihi batas upload server',
                    UPLOAD_ERR_FORM_SIZE => 'Ukuran foto melebihi batas formulir',
                    UPLOAD_ERR_PARTIAL => 'Foto hanya terunggah sebagian, silakan coba kembali',
                    UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara upload tidak tersedia di server',
                    UPLOAD_ERR_CANT_WRITE => 'Server gagal menyimpan file foto',
                    UPLOAD_ERR_EXTENSION => 'Upload foto dihentikan oleh konfigurasi server',
                ];
                respond(false, null, $uploadErrors[$file['error']] ?? 'Terjadi kesalahan saat mengunggah foto', 400);
            }
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (!in_array($ext, $allowed)) {
                respond(false, null, 'Format file foto harus berupa JPG, PNG, atau WEBP', 400);
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                respond(false, null, 'Ukuran foto maksimal 5 MB', 400);
            }
            $fotoFilename = 'foto_pegawai_' . ($target_id ?: 'new') . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $upload_dir . $fotoFilename)) {
                respond(false, null, 'Gagal mengunggah file foto', 500);
            }
        }

        if ($action === 'profile' || ($target_id && ($action === 'update' || !empty($_FILES['foto'])))) {
            // Update profile pegawai/petugas
            if (!$target_id) respond(false, null, 'ID petugas diperlukan', 400);

            // Ambil data lama untuk hapus foto lama jika ada upload baru
            $stmtOld = $db->prepare('SELECT foto FROM petugas WHERE id = ?');
            $stmtOld->execute([$target_id]);
            $oldData = $stmtOld->fetch();

            $sql = 'UPDATE petugas SET nama = ?, gelar_depan = ?, gelar_belakang = ?, email = ?';
            $params = [$nama, $gelar_depan ?: null, $gelar_belakang ?: null, $email];

            if ($nip) {
                $sql .= ', nip = ?';
                $params[] = $nip;
            }
            if ($jabatan) {
                $sql .= ', jabatan = ?';
                $params[] = $jabatan;
            }
            if (!empty($body['role'])) {
                $sql .= ', role = ?';
                $params[] = $body['role'];
            }
            if ($pass_val) {
                $sql .= ', password = ?';
                $params[] = password_hash($pass_val, PASSWORD_DEFAULT);
            }
            if ($fotoFilename) {
                $sql .= ', foto = ?';
                $params[] = $fotoFilename;

                // Hapus foto lama jika ada
                if (!empty($oldData['foto']) && file_exists($upload_dir . $oldData['foto'])) {
                    @unlink($upload_dir . $oldData['foto']);
                }
            }

            $sql .= ' WHERE id = ?';
            $params[] = $target_id;

            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);

                // Kembalikan data profil terbaru
                $stmtGet = $db->prepare('SELECT id, nip, nama, email, jabatan, COALESCE(role, "admin") AS role, gelar_depan, gelar_belakang, foto FROM petugas WHERE id = ?');
                $stmtGet->execute([$target_id]);
                $updated = $stmtGet->fetch();
                $updated['nama_lengkap'] = formatNamaGelar($updated['nama'], $updated['gelar_depan'], $updated['gelar_belakang']);

                respond(true, $updated, 'Profil berhasil diperbarui');
            } catch (PDOException $e) {
                respond(false, null, 'Gagal memperbarui profil: ' . $e->getMessage(), 409);
            }
        } else {
            // Tambah Petugas Baru
            if (!$nip || !$nama || !$email) respond(false, null, 'nip, nama, dan email wajib diisi', 400);
            try {
                $hashedPass = $pass_val ? password_hash($pass_val, PASSWORD_DEFAULT) : password_hash('admin123', PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO petugas (nip, nama, gelar_depan, gelar_belakang, email, jabatan, role, password, foto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $nip, $nama, $gelar_depan ?: null, $gelar_belakang ?: null,
                    $email, $jabatan ?: null, $role_val ?: 'admin', $hashedPass, $fotoFilename
                ]);
                $newId = (int)$db->lastInsertId();
                respond(true, ['id' => $newId], 'Petugas berhasil ditambahkan', 201);
            } catch (PDOException $e) {
                respond(false, null, 'NIP atau email sudah terdaftar: ' . $e->getMessage(), 409);
            }
        }
        break;

    case 'PUT':
        if (!$id) respond(false, null, 'ID petugas diperlukan', 400);
        $body = getRequestBody();
        $nip            = trim($body['nip'] ?? '');
        $nama           = trim($body['nama'] ?? '');
        $gelar_depan    = trim($body['gelar_depan'] ?? '');
        $gelar_belakang = trim($body['gelar_belakang'] ?? '');
        $email          = trim($body['email'] ?? '');
        $jabatan        = trim($body['jabatan'] ?? '');
        $role_val       = trim($body['role'] ?? 'admin');
        $pass_val       = trim($body['password'] ?? '');

        if (!$nip || !$nama || !$email) respond(false, null, 'nip, nama, dan email wajib diisi', 400);
        try {
            if ($pass_val) {
                $hashedPass = password_hash($pass_val, PASSWORD_DEFAULT);
                $stmt = $db->prepare('UPDATE petugas SET nip = ?, nama = ?, gelar_depan = ?, gelar_belakang = ?, email = ?, jabatan = ?, role = ?, password = ? WHERE id = ?');
                $stmt->execute([$nip, $nama, $gelar_depan ?: null, $gelar_belakang ?: null, $email, $jabatan ?: null, $role_val ?: 'admin', $hashedPass, $id]);
            } else {
                $stmt = $db->prepare('UPDATE petugas SET nip = ?, nama = ?, gelar_depan = ?, gelar_belakang = ?, email = ?, jabatan = ?, role = ? WHERE id = ?');
                $stmt->execute([$nip, $nama, $gelar_depan ?: null, $gelar_belakang ?: null, $email, $jabatan ?: null, $role_val ?: 'admin', $id]);
            }
            respond(true, null, 'Petugas berhasil diupdate');
        } catch (PDOException $e) {
            respond(false, null, 'NIP atau email sudah terdaftar: ' . $e->getMessage(), 409);
        }
        break;

    case 'DELETE':
        if (!$id) respond(false, null, 'ID petugas diperlukan', 400);
        try {
            // Hapus file foto jika ada
            $stmtFoto = $db->prepare('SELECT foto FROM petugas WHERE id = ?');
            $stmtFoto->execute([$id]);
            $p = $stmtFoto->fetch();
            if (!empty($p['foto'])) {
                $filePhoto = __DIR__ . '/../uploads/petugas/' . $p['foto'];
                if (file_exists($filePhoto)) @unlink($filePhoto);
            }

            $stmt = $db->prepare('DELETE FROM petugas WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) respond(false, null, 'Petugas tidak ditemukan', 404);
            respond(true, null, 'Petugas berhasil dihapus');
        } catch (PDOException $e) {
            respond(false, null, 'Tidak dapat menghapus petugas yang masih memiliki surat', 409);
        }
        break;

    default:
        respond(false, null, 'Method tidak didukung', 405);
}
