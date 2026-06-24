<?php
// Aktifkan pelaporan error untuk debugging (opsional, matikan di produksi)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// ==========================================
// 1. KONFIGURASI DATABASE
// ==========================================
define('DB_ENGINE', getenv('DB_ENGINE') ?: ($_ENV['DB_ENGINE'] ?? 'sqlite'));

// Konfigurasi MySQL (hanya digunakan jika DB_ENGINE = 'mysql')
define('DB_HOST', getenv('MYSQLHOST') ?: ($_ENV['MYSQLHOST'] ?? 'localhost'));
define('DB_PORT', getenv('MYSQLPORT') ?: ($_ENV['MYSQLPORT'] ?? '3306'));
define('DB_NAME', getenv('MYSQLDATABASE') ?: ($_ENV['MYSQLDATABASE'] ?? 'db_kotak_saran'));
define('DB_USER', getenv('MYSQLUSER') ?: ($_ENV['MYSQLUSER'] ?? 'root'));
define('DB_PASS', getenv('MYSQLPASSWORD') ?: ($_ENV['MYSQLPASSWORD'] ?? ''));

// ==========================================
// 2. KONEKSI & MIGRASI DATABASE
// ==========================================
try {
    if (DB_ENGINE === 'sqlite') {
        $dbPath = __DIR__ . '/saran.db';
        $pdo = new PDO("sqlite:" . $dbPath);
    } else {
        // Coba koneksi ke MySQL untuk membuat database terlebih dahulu jika belum ada (untuk lokal)
        try {
            $dsnWithoutDb = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
            $pdoTemp = new PDO($dsnWithoutDb, DB_USER, DB_PASS);
            $pdoTemp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdoTemp->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdoTemp = null; 
        } catch (PDOException $e) {
            // Abaikan jika tidak punya hak akses CREATE DATABASE (seperti di Railway di mana DB sudah otomatis dibuat)
        }

        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
    }
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Auto-migration untuk tabel `saran`
    if (DB_ENGINE === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS saran (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nama TEXT NOT NULL,
            kategori TEXT NOT NULL,
            isi_saran TEXT NOT NULL,
            likes INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS saran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama VARCHAR(100) NOT NULL,
            kategori VARCHAR(50) NOT NULL,
            isi_saran TEXT NOT NULL,
            likes INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (PDOException $e) {
    die("Koneksi Database Gagal: " . $e->getMessage());
}

// ==========================================
// 3. KEAMANAN & TOKEN CSRF
// ==========================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper untuk waktu yang lalu (time ago)
function waktu_lalu($timestamp) {
    $selisih = time() - strtotime($timestamp);
    if ($selisih < 1) { return 'Baru saja'; }
    $detik = [
        365 * 24 * 60 * 60 => 'tahun',
        30 * 24 * 60 * 60  => 'bulan',
        24 * 60 * 60       => 'hari',
        60 * 60            => 'jam',
        60                 => 'menit',
        1                  => 'detik'
    ];
    foreach ($detik as $secs => $str) {
        $d = $selisih / $secs;
        if ($d >= 1) {
            $r = round($d);
            return $r . ' ' . $str . ' yang lalu';
        }
    }
    return date('d M Y', strtotime($timestamp));
}

// ==========================================
// 4. PROSES REQUEST POST (SUBMIT & LIKE)
// ==========================================
$successMessage = '';
$errorMessage = '';

if (isset($_SESSION['flash_success'])) {
    $successMessage = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $errorMessage = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validasi CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['flash_error'] = "Token keamanan tidak valid. Silakan coba lagi.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    $action = $_POST['action'];

    // Simpan Saran Baru
    if ($action === 'submit_saran') {
        $nama = trim($_POST['nama']);
        $kategori = trim($_POST['kategori']);
        $isi_saran = trim($_POST['isi_saran']);

        // Default nama ke 'Anonim' jika kosong
        if ($nama === '') {
            $nama = 'Anonim';
        }

        $allowedKategori = ['Saran', 'Kritik', 'Ide', 'Pertanyaan'];
        
        if (!in_array($kategori, $allowedKategori)) {
            $_SESSION['flash_error'] = "Kategori tidak valid.";
        } elseif (empty($isi_saran)) {
            $_SESSION['flash_error'] = "Isi saran tidak boleh kosong.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO saran (nama, kategori, isi_saran) VALUES (:nama, :kategori, :isi_saran)");
                $stmt->execute([
                    ':nama' => htmlspecialchars($nama, ENT_QUOTES, 'UTF-8'),
                    ':kategori' => $kategori,
                    ':isi_saran' => htmlspecialchars($isi_saran, ENT_QUOTES, 'UTF-8')
                ]);
                $_SESSION['flash_success'] = "Saran Anda berhasil disimpan! Terima kasih banyak.";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Gagal menyimpan data: " . $e->getMessage();
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Tambah Like (Upvote)
    if ($action === 'like_saran') {
        $id = (int)$_POST['saran_id'];
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE saran SET likes = likes + 1 WHERE id = :id");
                $stmt->execute([':id' => $id]);
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Gagal menyukai saran.";
            }
        }

        // Kembalikan ke halaman dengan mempertahankan filter GET jika ada
        $redirectUrl = $_SERVER['PHP_SELF'];
        $queryParams = [];
        if (!empty($_POST['redirect_search'])) {
            $queryParams['search'] = $_POST['redirect_search'];
        }
        if (!empty($_POST['redirect_kategori'])) {
            $queryParams['kategori'] = $_POST['redirect_kategori'];
        }
        if (!empty($queryParams)) {
            $redirectUrl .= '?' . http_build_query($queryParams);
        }

        header("Location: " . $redirectUrl);
        exit;
    }
}

// ==========================================
// 5. QUERY DATA & STATISTIK
// ==========================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$kategoriFilter = isset($_GET['kategori']) ? trim($_GET['kategori']) : 'Semua';

// Hitung Statistik
try {
    $statsStmt = $pdo->query("SELECT COUNT(*) as total_saran, SUM(likes) as total_likes FROM saran");
    $stats = $statsStmt->fetch();
    $totalSaran = $stats['total_saran'] ?? 0;
    $totalLikes = $stats['total_likes'] ?? 0;

    $catStatsStmt = $pdo->query("SELECT kategori, COUNT(*) as count FROM saran GROUP BY kategori ORDER BY count DESC LIMIT 1");
    $popularCatRow = $catStatsStmt->fetch();
    $popularCategory = $popularCatRow ? $popularCatRow['kategori'] : "-";
} catch (PDOException $e) {
    $totalSaran = 0;
    $totalLikes = 0;
    $popularCategory = "-";
}

// Ambil List Saran dengan Filter
$sql = "SELECT * FROM saran WHERE 1=1";
$queryParams = [];

if ($search !== '') {
    $sql .= " AND (nama LIKE :search OR isi_saran LIKE :search)";
    $queryParams[':search'] = '%' . $search . '%';
}

if ($kategoriFilter !== 'Semua') {
    $sql .= " AND kategori = :kategori";
    $queryParams[':kategori'] = $kategoriFilter;
}

$sql .= " ORDER BY created_at DESC LIMIT 50";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($queryParams);
    $suggestions = $stmt->fetchAll();
} catch (PDOException $e) {
    $suggestions = [];
    $errorMessage = "Gagal memuat saran: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kotak Saran & Aspirasi Digital</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: rgba(20, 26, 42, 0.65);
            --card-border: rgba(255, 255, 255, 0.08);
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --primary-glow: rgba(99, 102, 241, 0.15);
            --accent: #d946ef;
            
            --cat-saran: #10b981;
            --cat-saran-bg: rgba(16, 185, 129, 0.12);
            --cat-kritik: #ef4444;
            --cat-kritik-bg: rgba(239, 68, 68, 0.12);
            --cat-ide: #3b82f6;
            --cat-ide-bg: rgba(59, 130, 246, 0.12);
            --cat-tanya: #8b5cf6;
            --cat-tanya-bg: rgba(139, 92, 246, 0.12);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
            line-height: 1.6;
        }

        /* Background ambient blobs */
        body::before {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, rgba(0,0,0,0) 70%);
            top: -100px;
            left: -100px;
            z-index: -1;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(217, 70, 239, 0.2) 0%, rgba(0,0,0,0) 70%);
            bottom: 50px;
            right: -150px;
            z-index: -1;
            pointer-events: none;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2.5rem 1.5rem;
        }

        /* Header Styling */
        header {
            text-align: center;
            margin-bottom: 3rem;
            animation: fadeInDown 0.8s ease-out;
        }

        h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.75rem;
            font-weight: 700;
            background: linear-gradient(135deg, #fff 30%, #a5b4fc 70%, #f472b6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.75rem;
            letter-spacing: -0.5px;
        }

        header p {
            color: var(--text-muted);
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto;
            font-weight: 300;
        }

        /* Layout Grid */
        .layout-grid {
            display: grid;
            grid-template-columns: 1.2fr 1.8fr;
            gap: 2rem;
            align-items: start;
        }

        /* Card Base (Glassmorphism) */
        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 2rem;
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
            transition: transform 0.3s ease, border-color 0.3s ease;
        }

        .glass-card:hover {
            border-color: rgba(255, 255, 255, 0.15);
        }

        .card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #fff;
        }

        .card-title svg {
            color: var(--primary);
        }

        /* Form Controls */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-main);
        }

        .form-input, .form-textarea {
            width: 100%;
            background: rgba(10, 14, 25, 0.8);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.85rem 1rem;
            color: #fff;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .form-textarea {
            height: 150px;
            resize: none;
        }

        /* Custom Radio Buttons as Chips */
        .category-chips {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .chip-label {
            position: relative;
            cursor: pointer;
        }

        .chip-label input {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .chip-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem;
            background: rgba(10, 14, 25, 0.6);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-muted);
            transition: all 0.3s ease;
            text-align: center;
        }

        .chip-label input:checked + .chip-content {
            color: #fff;
            box-shadow: 0 0 12px var(--primary-glow);
        }

        .chip-label:nth-of-type(1) input:checked + .chip-content { border-color: var(--cat-saran); background: var(--cat-saran-bg); color: var(--cat-saran); }
        .chip-label:nth-of-type(2) input:checked + .chip-content { border-color: var(--cat-kritik); background: var(--cat-kritik-bg); color: var(--cat-kritik); }
        .chip-label:nth-of-type(3) input:checked + .chip-content { border-color: var(--cat-ide); background: var(--cat-ide-bg); color: var(--cat-ide); }
        .chip-label:nth-of-type(4) input:checked + .chip-content { border-color: var(--cat-tanya); background: var(--cat-tanya-bg); color: var(--cat-tanya); }

        .chip-content:hover {
            border-color: rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.02);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            padding: 0.9rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%);
            border: none;
            border-radius: 12px;
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.45);
        }

        .btn:active {
            transform: translateY(0);
        }

        /* Alert System */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            animation: slideIn 0.4s ease-out;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
        }

        .alert-icon {
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* Statistics Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 1rem;
            text-align: center;
        }

        .stat-val {
            font-family: 'Outfit', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 0.25rem;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Filter & Search Bar */
        .filter-section {
            margin-bottom: 2rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .search-box {
            position: relative;
            width: 100%;
        }

        .search-input {
            width: 100%;
            background: rgba(10, 14, 25, 0.8);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.85rem 1rem 0.85rem 2.75rem;
            color: #fff;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
        }

        /* Category Filter Tabs */
        .filter-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 1rem;
        }

        .filter-tab {
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-muted);
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--card-border);
            transition: all 0.3s ease;
        }

        .filter-tab:hover {
            color: #fff;
            border-color: rgba(255, 255, 255, 0.15);
        }

        .filter-tab.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            box-shadow: 0 4px 12px var(--primary-glow);
        }

        /* Suggestions List */
        .suggestions-list {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .suggestion-card {
            background: rgba(255, 255, 255, 0.015);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            animation: fadeInUp 0.5s ease-out;
        }

        .suggestion-card:hover {
            transform: translateY(-2px);
            border-color: rgba(99, 102, 241, 0.25);
            background: rgba(99, 102, 241, 0.01);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            gap: 0.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4f46e5, #ec4899);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
            color: #fff;
            text-transform: uppercase;
        }

        .user-name {
            font-size: 0.95rem;
            font-weight: 600;
            color: #fff;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-saran { color: var(--cat-saran); background: var(--cat-saran-bg); border: 1px solid rgba(16, 185, 129, 0.2); }
        .badge-kritik { color: var(--cat-kritik); background: var(--cat-kritik-bg); border: 1px solid rgba(239, 68, 68, 0.2); }
        .badge-ide { color: var(--cat-ide); background: var(--cat-ide-bg); border: 1px solid rgba(59, 130, 246, 0.2); }
        .badge-pertanyaan { color: var(--cat-tanya); background: var(--cat-tanya-bg); border: 1px solid rgba(139, 92, 246, 0.2); }

        .card-body {
            font-size: 0.95rem;
            color: #d1d5db;
            margin-bottom: 1.25rem;
            white-space: pre-line;
            word-break: break-word;
        }

        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid rgba(255, 255, 255, 0.04);
            padding-top: 0.75rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .post-time {
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        /* Like Button and form */
        .like-form {
            display: inline;
        }

        .like-btn {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 0.4rem 0.8rem;
            color: var(--text-muted);
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.3s ease;
        }

        .like-btn:hover {
            background: rgba(239, 68, 68, 0.08);
            border-color: rgba(239, 68, 68, 0.3);
            color: #ef4444;
            transform: scale(1.05);
        }

        .like-btn svg {
            transition: transform 0.2s ease;
        }

        .like-btn:hover svg {
            transform: scale(1.2);
            fill: #ef4444;
        }

        .like-btn.liked {
            color: #ef4444;
            background: rgba(239, 68, 68, 0.05);
            border-color: rgba(239, 68, 68, 0.2);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }

        .empty-icon {
            color: var(--primary);
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: #fff;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        /* Footer styling */
        footer {
            text-align: center;
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 1px solid var(--card-border);
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        /* Animations */
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .layout-grid {
                grid-template-columns: 1fr;
            }
            
            h1 {
                font-size: 2.25rem;
            }
        }

        @media (max-width: 480px) {
            .stats-row {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .category-chips {
                grid-template-columns: 1fr;
            }

            .container {
                padding: 1.5rem 1rem;
            }
        }
    </style>
</head>
<body>

    <div class="container">
        <!-- HEADER -->
        <header>
            <h1>Kotak Saran & Aspirasi</h1>
            <p>Bagikan saran, ide kreatif, kritik membangun, atau pertanyaan Anda untuk masa depan layanan yang lebih baik.</p>
        </header>

        <!-- LAYOUT GRID -->
        <div class="layout-grid">
            
            <!-- LEFT COLUMN: FORM -->
            <div class="glass-card">
                <h2 class="card-title">
                    <!-- Icon Form -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    Kirim Aspirasi Baru
                </h2>

                <!-- Flash Alert Messages -->
                <?php if ($successMessage): ?>
                    <div class="alert alert-success">
                        <svg class="alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <span><?= $successMessage ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage): ?>
                    <div class="alert alert-error">
                        <svg class="alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span><?= $errorMessage ?></span>
                    </div>
                <?php endif; ?>

                <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="submit_saran">

                    <!-- Nama -->
                    <div class="form-group">
                        <label for="nama" class="form-label">Nama Pengirim (Opsional)</label>
                        <input type="text" id="nama" name="nama" class="form-input" placeholder="Tulis nama Anda (misal: Budi) atau kosongkan untuk Anonim" autocomplete="off" maxlength="100">
                    </div>

                    <!-- Kategori -->
                    <div class="form-group">
                        <label class="form-label">Pilih Kategori</label>
                        <div class="category-chips">
                            
                            <label class="chip-label">
                                <input type="radio" name="kategori" value="Saran" checked>
                                <span class="chip-content">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.886H3.877l4.896 3.557L6.86 18.33 12 14.772l5.14 3.557-1.913-5.887 4.896-3.557h-6.21Z"/></svg>
                                    Saran
                                </span>
                            </label>

                            <label class="chip-label">
                                <input type="radio" name="kategori" value="Kritik">
                                <span class="chip-content">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                    Kritik
                                </span>
                            </label>

                            <label class="chip-label">
                                <input type="radio" name="kategori" value="Ide">
                                <span class="chip-content">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A5 5 0 0 0 8 8c0 1 .3 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg>
                                    Ide
                                </span>
                            </label>

                            <label class="chip-label">
                                <input type="radio" name="kategori" value="Pertanyaan">
                                <span class="chip-content">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                    Pertanyaan
                                </span>
                            </label>

                        </div>
                    </div>

                    <!-- Isi Saran -->
                    <div class="form-group">
                        <label for="isi_saran" class="form-label">Aspirasi atau Saran Anda *</label>
                        <textarea id="isi_saran" name="isi_saran" class="form-textarea" placeholder="Tuliskan keluhan, ide kreatif, atau saran detail Anda di sini..." required></textarea>
                    </div>

                    <button type="submit" class="btn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        Kirim Aspirasi
                    </button>
                </form>
            </div>

            <!-- RIGHT COLUMN: SUGGESTIONS LIST -->
            <div class="glass-card">
                
                <!-- STATISTICS PANEL -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-val"><?= number_format($totalSaran) ?></div>
                        <div class="stat-label">Total Saran</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val"><?= number_format($totalLikes) ?></div>
                        <div class="stat-label">Total Suka</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val" style="font-size: 1.15rem; height: 2.25rem; display: flex; align-items: center; justify-content: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?= htmlspecialchars($popularCategory, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="stat-label">Populer</div>
                    </div>
                </div>

                <h2 class="card-title">
                    <!-- Icon List -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                    Aspirasi Masuk
                </h2>

                <!-- SEARCH & FILTER -->
                <div class="filter-section">
                    <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="GET">
                        <?php if ($kategoriFilter !== 'Semua'): ?>
                            <input type="hidden" name="kategori" value="<?= htmlspecialchars($kategoriFilter, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                        <div class="search-box">
                            <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" name="search" class="search-input" placeholder="Cari berdasarkan nama atau isi saran..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.submit()">
                        </div>
                    </form>

                    <!-- Filter Tabs -->
                    <div class="filter-tabs">
                        <?php
                        $categories = ['Semua', 'Saran', 'Kritik', 'Ide', 'Pertanyaan'];
                        foreach ($categories as $cat):
                            $activeClass = ($kategoriFilter === $cat) ? 'active' : '';
                            // Build URL query
                            $urlParams = [];
                            if ($search !== '') {
                                $urlParams['search'] = $search;
                            }
                            if ($cat !== 'Semua') {
                                $urlParams['kategori'] = $cat;
                            }
                            $url = empty($urlParams) ? $_SERVER['PHP_SELF'] : $_SERVER['PHP_SELF'] . '?' . http_build_query($urlParams);
                        ?>
                            <a href="<?= htmlspecialchars($url) ?>" class="filter-tab <?= $activeClass ?>">
                                <?= $cat ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- SUGGESTIONS CONTAINER -->
                <div class="suggestions-list">
                    <?php if (empty($suggestions)): ?>
                        <div class="empty-state">
                            <svg class="empty-icon" xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                            <h3>Aspirasi Tidak Ditemukan</h3>
                            <p>Belum ada saran untuk kriteria pencarian atau filter saat ini.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($suggestions as $saran): ?>
                            <?php 
                            $badgeClass = 'badge-saran';
                            if ($saran['kategori'] === 'Kritik') $badgeClass = 'badge-kritik';
                            elseif ($saran['kategori'] === 'Ide') $badgeClass = 'badge-ide';
                            elseif ($saran['kategori'] === 'Pertanyaan') $badgeClass = 'badge-pertanyaan';
                            ?>
                            <div class="suggestion-card">
                                <div class="card-header">
                                    <div class="user-info">
                                        <div class="user-avatar">
                                            <?= substr(htmlspecialchars($saran['nama'], ENT_QUOTES, 'UTF-8'), 0, 1) ?>
                                        </div>
                                        <span class="user-name"><?= htmlspecialchars($saran['nama'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <span class="badge <?= $badgeClass ?>"><?= $saran['kategori'] ?></span>
                                </div>
                                
                                <div class="card-body">
                                    <?= nl2br(htmlspecialchars($saran['isi_saran'], ENT_QUOTES, 'UTF-8')) ?>
                                </div>

                                <div class="card-footer">
                                    <div class="post-time">
                                        <!-- Clock Icon -->
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        <?= waktu_lalu($saran['created_at']) ?>
                                    </div>
                                    
                                    <!-- Like Form -->
                                    <form class="like-form" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="like_saran">
                                        <input type="hidden" name="saran_id" value="<?= $saran['id'] ?>">
                                        
                                        <!-- Keep search/filters state for redirect -->
                                        <?php if ($search !== ''): ?>
                                            <input type="hidden" name="redirect_search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                        <?php endif; ?>
                                        <?php if ($kategoriFilter !== 'Semua'): ?>
                                            <input type="hidden" name="redirect_kategori" value="<?= htmlspecialchars($kategoriFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <?php endif; ?>

                                        <button type="submit" class="like-btn <?= $saran['likes'] > 0 ? 'liked' : '' ?>">
                                            <!-- Thumbs up icon -->
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>
                                            Suka (<?= number_format($saran['likes']) ?>)
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>

        </div>

        <!-- FOOTER -->
        <footer>
            <p>&copy; <?= date('Y') ?> Kotak Saran & Aspirasi. Dibuat dengan &hearts; menggunakan PHP Native.</p>
        </footer>
    </div>

</body>
</html>
