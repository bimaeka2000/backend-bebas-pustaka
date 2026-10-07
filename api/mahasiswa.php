<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();
$db     = getDB();
$method = getMethod();
$nim    = $_GET['nim'] ?? null;

switch ($method) {
    case 'GET':
        if ($nim) {
            $stmt = $db->prepare(
                'SELECT m.*, j.nama_jurusan, j.fakultas,
                        rp.buku_dipinjam, rp.tunggakan_denda, rp.status_bebas_tanggungan
                 FROM mahasiswa m
                 JOIN jurusan j ON j.id = m.id_jurusan
                 LEFT JOIN riwayat_pinjaman rp ON rp.nim = m.nim
                 WHERE m.nim = ?'
            );
            $stmt->execute([$nim]);
            $data = $stmt->fetch();
            if (!$data) respond(false, null, 'Mahasiswa tidak ditemukan', 404);
            respond(true, $data);
        } else {
            // Dukung filter: angkatan, id_jurusan, search
            $where  = [];
            $params = [];

            if (!empty($_GET['angkatan'])) {
                $where[] = 'm.angkatan = ?';
                $params[] = (int)$_GET['angkatan'];
            }
            if (!empty($_GET['id_jurusan'])) {
                $where[] = 'm.id_jurusan = ?';
                $params[] = (int)$_GET['id_jurusan'];
            }
            if (!empty($_GET['search'])) {
                $where[] = '(m.nama LIKE ? OR m.nim LIKE ? OR m.email LIKE ?)';
                $q = '%' . $_GET['search'] . '%';
                $params[] = $q; $params[] = $q; $params[] = $q;
            }

            // Dukung pagination jika ada parameter page atau limit/per_page
            $isAll = isset($_GET['all']) && ($_GET['all'] === 'true' || $_GET['all'] === '1');
            $usePagination = !$isAll && (isset($_GET['page']) || isset($_GET['limit']) || isset($_GET['per_page']));

            if ($usePagination) {
                // Hitung total record sesuai filter
                $countSql = 'SELECT COUNT(*) as total FROM mahasiswa m JOIN jurusan j ON j.id = m.id_jurusan';
                if ($where) $countSql .= ' WHERE ' . implode(' AND ', $where);
                $countStmt = $db->prepare($countSql);
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();

                $pageParam  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                $limitParam = isset($_GET['limit']) ? (int)$_GET['limit'] : (isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10);

                $limit      = max(1, min(200, $limitParam));
                $totalPages = $total > 0 ? (int)ceil($total / $limit) : 1;
                $page       = max(1, min($pageParam, $totalPages));
                $offset     = ($page - 1) * $limit;

                $sql = 'SELECT m.nim, m.nama, m.email, m.no_telepon, m.id_jurusan, m.angkatan, m.created_at,
                               j.nama_jurusan, j.fakultas,
                               rp.buku_dipinjam, rp.tunggakan_denda, rp.status_bebas_tanggungan
                        FROM mahasiswa m
                        JOIN jurusan j ON j.id = m.id_jurusan
                        LEFT JOIN riwayat_pinjaman rp ON rp.nim = m.nim';
                if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);

                $sortFieldParam = $_GET['sortField'] ?? $_GET['sort_field'] ?? $_GET['sort_by'] ?? '';
                $sortOrderParam = (string)($_GET['sortOrder'] ?? $_GET['sort_order'] ?? '');
                $orderDir = ($sortOrderParam === '-1' || strtolower($sortOrderParam) === 'desc') ? 'DESC' : 'ASC';

                $allowedSort = [
                    'nim'          => 'm.nim',
                    'nama'         => 'm.nama',
                    'email'        => 'm.email',
                    'angkatan'     => 'm.angkatan',
                    'nama_jurusan' => 'j.nama_jurusan',
                ];
                $orderBy = isset($allowedSort[$sortFieldParam]) ? "{$allowedSort[$sortFieldParam]} $orderDir" : "m.nama ASC";

                $sql .= " ORDER BY $orderBy LIMIT $limit OFFSET $offset";

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                respond(true, $rows, '', 200, [
                    'pagination' => [
                        'total'       => $total,
                        'page'        => $page,
                        'limit'       => $limit,
                        'total_pages' => $totalPages,
                    ],
                ]);
            } else {
                $sql = 'SELECT m.nim, m.nama, m.email, m.no_telepon, m.id_jurusan, m.angkatan, m.created_at,
                               j.nama_jurusan, j.fakultas,
                               rp.buku_dipinjam, rp.tunggakan_denda, rp.status_bebas_tanggungan
                        FROM mahasiswa m
                        JOIN jurusan j ON j.id = m.id_jurusan
                        LEFT JOIN riwayat_pinjaman rp ON rp.nim = m.nim';
                if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
                $sql .= ' ORDER BY m.nama';

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                respond(true, $stmt->fetchAll());
            }
        }
        break;

    case 'POST':
        $action = $_GET['action'] ?? '';
        if ($action === 'bulk') {
            $body = getRequestBody();
            $items = is_array($body) && isset($body['data']) ? $body['data'] : (is_array($body) ? $body : []);
            if (empty($items)) {
                respond(false, null, 'Data mahasiswa tidak boleh kosong', 400);
            }

            // Map jurusan untuk pencocokan nama atau id
            $jurusanMap = [];
            $allJur = $db->query('SELECT id, nama_jurusan FROM jurusan')->fetchAll();
            foreach ($allJur as $j) {
                $jurusanMap[strtolower(trim($j['nama_jurusan']))] = (int)$j['id'];
                $jurusanMap[(string)$j['id']] = (int)$j['id'];
            }
            $defaultJurId = !empty($allJur) ? (int)$allJur[0]['id'] : 1;

            $successCount = 0;
            $skippedCount = 0;
            $errors = [];

            $stmtMhs = $db->prepare(
                'INSERT INTO mahasiswa (nim, nama, email, no_telepon, id_jurusan, angkatan) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmtPinjam = $db->prepare('INSERT IGNORE INTO riwayat_pinjaman (nim) VALUES (?)');

            foreach ($items as $idx => $row) {
                $nim_val = trim($row['nim'] ?? '');
                $nama    = trim($row['nama'] ?? '');
                $email   = trim($row['email'] ?? '');
                $telp    = trim($row['no_telepon'] ?? $row['telp'] ?? $row['telepon'] ?? '');
                $angk    = (int)($row['angkatan'] ?? 0);

                if (!$nim_val || !$nama) {
                    $errors[] = "Baris " . ($idx + 1) . ": NIM dan Nama wajib diisi";
                    $skippedCount++;
                    continue;
                }

                // Default email jika kosong
                if (!$email) {
                    $email = $nim_val . '@student.ac.id';
                }

                // Default angkatan dari 4 digit pertama NIM jika belum ada
                if (!$angk && strlen($nim_val) >= 4) {
                    $prefix = (int)substr($nim_val, 0, 4);
                    if ($prefix >= 2000 && $prefix <= 2099) {
                        $angk = $prefix;
                    }
                }

                // Resolve jurusan
                $rawJur = trim((string)($row['id_jurusan'] ?? $row['jurusan'] ?? ''));
                $jurId  = $defaultJurId;
                if ($rawJur !== '') {
                    $lowJur = strtolower($rawJur);
                    if (isset($jurusanMap[$lowJur])) {
                        $jurId = $jurusanMap[$lowJur];
                    } else {
                        // Cari substring yang cocok
                        foreach ($allJur as $j) {
                            if (stripos($j['nama_jurusan'], $rawJur) !== false || stripos($rawJur, $j['nama_jurusan']) !== false) {
                                $jurId = (int)$j['id'];
                                break;
                            }
                        }
                    }
                }

                try {
                    $stmtMhs->execute([$nim_val, $nama, $email, $telp ?: null, $jurId, $angk ?: null]);
                    $stmtPinjam->execute([$nim_val]);
                    $successCount++;
                } catch (PDOException $e) {
                    $skippedCount++;
                    $errors[] = "NIM $nim_val sudah terdaftar atau gagal disimpan.";
                }
            }

            respond(true, [
                'imported' => $successCount,
                'skipped'  => $skippedCount,
                'errors'   => array_slice($errors, 0, 10),
            ], "Berhasil mengimpor $successCount data mahasiswa" . ($skippedCount > 0 ? " ($skippedCount data dilewati)" : ""));
        }

        $body    = getRequestBody();
        $nim_val = trim($body['nim']      ?? '');
        $nama    = trim($body['nama']     ?? '');
        $email   = trim($body['email']    ?? '');
        $telp    = trim($body['no_telepon'] ?? '');
        $jur     = (int)($body['id_jurusan'] ?? 0);
        $angk    = (int)($body['angkatan']   ?? 0);
        if (!$nim_val || !$nama || !$email || !$jur) {
            respond(false, null, 'nim, nama, email, id_jurusan wajib diisi', 400);
        }
        try {
            $db->beginTransaction();
            $stmt = $db->prepare(
                'INSERT INTO mahasiswa (nim, nama, email, no_telepon, id_jurusan, angkatan) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$nim_val, $nama, $email, $telp ?: null, $jur, $angk ?: null]);
            // Buat record pinjaman kosong
            $db->prepare('INSERT INTO riwayat_pinjaman (nim) VALUES (?)')->execute([$nim_val]);
            $db->commit();
            respond(true, ['nim' => $nim_val], 'Mahasiswa berhasil ditambahkan', 201);
        } catch (PDOException $e) {
            $db->rollBack();
            respond(false, null, 'NIM atau email sudah terdaftar', 409);
        }
        break;

    case 'PUT':
        if (!$nim) respond(false, null, 'NIM diperlukan', 400);
        $body  = getRequestBody();
        $nama  = trim($body['nama']       ?? '');
        $email = trim($body['email']      ?? '');
        $telp  = trim($body['no_telepon'] ?? '');
        $jur   = (int)($body['id_jurusan'] ?? 0);
        $angk  = (int)($body['angkatan']   ?? 0);
        if (!$nama || !$email || !$jur) respond(false, null, 'nama, email, id_jurusan wajib diisi', 400);
        try {
            $stmt = $db->prepare(
                'UPDATE mahasiswa SET nama=?, email=?, no_telepon=?, id_jurusan=?, angkatan=? WHERE nim=?'
            );
            $stmt->execute([$nama, $email, $telp ?: null, $jur, $angk ?: null, $nim]);
            if ($stmt->rowCount() === 0) respond(false, null, 'Mahasiswa tidak ditemukan', 404);
            respond(true, null, 'Data mahasiswa berhasil diupdate');
        } catch (PDOException $e) {
            respond(false, null, 'Email sudah terdaftar', 409);
        }
        break;

    case 'DELETE':
        if (!$nim) respond(false, null, 'NIM diperlukan', 400);
        $stmt = $db->prepare('DELETE FROM mahasiswa WHERE nim = ?');
        $stmt->execute([$nim]);
        if ($stmt->rowCount() === 0) respond(false, null, 'Mahasiswa tidak ditemukan', 404);
        respond(true, null, 'Mahasiswa berhasil dihapus');
        break;

    default:
        respond(false, null, 'Method tidak didukung', 405);
}
