<?php
/*
======================================================
 BOT TELEGRAM MANAJEMEN VIDEO + CAPTION RANDOM (PHP)
------------------------------------------------------
FITUR:
- Ambil video dari folder /videos di server.
- /start atau /next:
    -> kirim 1 video random yang BELUM PERNAH dikirim
       ke user (berdasarkan chat_id + video_logs).
    -> caption ikut random dari tabel video_captions.
- Jika semua video sudah pernah dikirim ke user itu
  atau stok video kosong -> notif stok kosong.
- Caption dikelola lewat halaman admin (video_captions).
======================================================
*/

//////////////////////////
// KONFIGURASI
//////////////////////////

$BOT_TOKEN = '8239112964:AAFAn8F6LbHeUnGa9QoDnU2bNlzovnwOtsU';  // contoh: 123456789:ABC...
$BOT_API   = "https://api.telegram.org/bot{$BOT_TOKEN}/";

// URL dasar website kamu (TANPA garis miring di akhir)
$BASE_URL  = 'https://bot.akay.web.id/'; // contoh: https://sales.akay.web.id

// Folder video relatif dari root project
$VIDEO_DIR        = __DIR__ . '/videos';        // folder fisik
$VIDEO_URL_PREFIX = $BASE_URL . '/videos';      // folder URL publik

// Konfigurasi database
$dbHost = 'localhost';
$dbName = 'u386246510_akayfile';      // ganti sesuai nama DB
$dbUser = 'u386246510_akayfile';        // ganti
$dbPass = 'Jndpusat2023';        // ganti

// DEFINISI KATEGORI (key di DB, label untuk user)
$CATEGORIES = [
    'alquran'   => 'Alquran',
    'jnd'       => 'Sandal JND',
    'akay'      => 'Sandal Akay',
    'parfum'    => 'Parfum',
    'campuran'  => 'Campuran (random semua)',
];

//////////////////////////
// KONEKSI DATABASE
//////////////////////////

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    error_log('DB connection error: ' . $e->getMessage());
    exit;
}

//////////////////////////
// FUNGSI BANTUAN
//////////////////////////

function tgRequest(string $method, array $params = [])
{
    global $BOT_API;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $BOT_API . $method,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => $params,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        error_log('Telegram API error: ' . curl_error($ch));
    }
    curl_close($ch);
    return $response ? json_decode($response, true) : null;
}

/**
 * Deteksi kategori dari path file.
 * Contoh:
 *  videos/alquran/video1.mp4  -> alquran
 *  videos/jnd/video2.mp4      -> jnd
 *  videos/abc.mp4             -> campuran
 */
function detectCategoryFromPath(string $relativePath): string
{
    // relativePath contoh: "videos/alquran/video1.mp4"
    $parts = explode('/', str_replace('\\', '/', $relativePath));
    // [0] => videos, [1] => alquran, [2] => video1.mp4
    if (count($parts) >= 3) {
        $cat = strtolower(trim($parts[1]));
        if ($cat !== '') {
            return $cat;
        }
    }
    return 'campuran';
}

/**
 * Sinkronisasi folder videos/ ke tabel videos.
 * Setiap file video baru akan dicatat sekali + kategori.
 * Hanya format video, BUKAN GIF.
 */
function syncVideosFromFolder(PDO $pdo, string $videoDir): void
{
    if (!is_dir($videoDir)) {
        return;
    }

    $extAllow = ['mp4', 'mov', 'mkv', 'avi'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($videoDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileinfo) {
        if (!$fileinfo->isFile()) {
            continue;
        }

        $ext = strtolower(pathinfo($fileinfo->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, $extAllow, true)) {
            continue;
        }

        $absolutePath = $fileinfo->getPathname();
        $relativePath = ltrim(str_replace(__DIR__, '', $absolutePath), '/\\'); // contoh: videos/alquran/video1.mp4

        $category = detectCategoryFromPath($relativePath);

        $stmt = $pdo->prepare("SELECT id FROM videos WHERE file_path = :p LIMIT 1");
        $stmt->execute([':p' => $relativePath]);
        $row = $stmt->fetch();

        if ($row) {
            // update kategori jika belum terisi
            $upd = $pdo->prepare("UPDATE videos SET category = :cat WHERE id = :id");
            $upd->execute([':cat' => $category, ':id' => $row['id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO videos (file_path, category) VALUES (:p, :cat)");
            $ins->execute([':p' => $relativePath, ':cat' => $category]);
        }
    }
}

/**
 * Ambil kategori user dari user_prefs.
 */
function getUserCategory(PDO $pdo, int $chatId): ?string
{
    $stmt = $pdo->prepare("SELECT category FROM user_prefs WHERE chat_id = :id LIMIT 1");
    $stmt->execute([':id' => $chatId]);
    $row = $stmt->fetch();
    return $row ? $row['category'] : null;
}

/**
 * Simpan kategori user ke user_prefs.
 */
function setUserCategory(PDO $pdo, int $chatId, string $category): void
{
    $stmt = $pdo->prepare("
        INSERT INTO user_prefs (chat_id, category)
        VALUES (:id, :cat)
        ON DUPLICATE KEY UPDATE category = VALUES(category), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([':id' => $chatId, ':cat' => $category]);
}

/**
 * Ambil video yang BELUM PERNAH dikirim ke SIAPA PUN.
 * 1 video = 1 user (global).
 * - $categoryKey = 'campuran'  -> semua kategori
 * - selain itu                  -> hanya kategori tsb
 * Pemilihan urut dari yang paling awal (created_at) supaya rapi:
 * video 1,2,3,4,5 ... bergilir ke user berikutnya.
 */
function getRandomUnsentVideo(PDO $pdo, int $chatId, string $categoryKey): ?array
{
    if ($categoryKey === 'campuran') {
        $sql = "
            SELECT v.id, v.file_path
            FROM videos v
            WHERE v.file_path NOT LIKE '%.gif'
              AND v.file_path NOT LIKE '%.GIF'
              AND NOT EXISTS (
                  SELECT 1
                  FROM video_logs l
                  WHERE l.video_id = v.id
              )
            ORDER BY v.created_at ASC, v.id ASC
            LIMIT 1
        ";
        $stmt = $pdo->query($sql);
    } else {
        $sql = "
            SELECT v.id, v.file_path
            FROM videos v
            WHERE v.category = :cat
              AND v.file_path NOT LIKE '%.gif'
              AND v.file_path NOT LIKE '%.GIF'
              AND NOT EXISTS (
                  SELECT 1
                  FROM video_logs l
                  WHERE l.video_id = v.id
              )
            ORDER BY v.created_at ASC, v.id ASC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':cat' => $categoryKey,
        ]);
    }

    $row = $stmt->fetch();
    return $row ?: null;
}


/**
 * Catat bahwa video_id sudah dikirim ke chat_id.
 */
function logVideoSent(PDO $pdo, int $videoId, int $chatId): void
{
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO video_logs (video_id, chat_id)
        VALUES (:v, :c)
    ");
    $stmt->execute([
        ':v' => $videoId,
        ':c' => $chatId,
    ]);
}

/**
 * Ambil caption random berdasarkan kategori.
 * Jika tidak ada untuk kategori itu, fallback ke 'campuran'.
 */
function getRandomCaptionByCategory(PDO $pdo, string $categoryKey): ?string
{
    $stmt = $pdo->prepare("
        SELECT caption_text
        FROM video_captions
        WHERE category = :cat
        ORDER BY RAND()
        LIMIT 1
    ");
    $stmt->execute([':cat' => $categoryKey]);
    $row = $stmt->fetch();

    if ($row) {
        return $row['caption_text'];
    }

    // fallback ke campuran
    if ($categoryKey !== 'campuran') {
        $stmt = $pdo->prepare("
            SELECT caption_text
            FROM video_captions
            WHERE category = 'campuran'
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? $row['caption_text'] : null;
    }

    return null;
}

/**
 * Ambil max $limit hashtag random berdasarkan kategori.
 * Fallback ke kategori 'campuran' jika kosong.
 */
function getRandomHashtags(PDO $pdo, string $categoryKey, int $limit = 5): array
{
    $stmt = $pdo->prepare("
        SELECT tag_text
        FROM video_hashtags
        WHERE category = :cat
        ORDER BY RAND()
        LIMIT {$limit}
    ");
    $stmt->execute([':cat' => $categoryKey]);
    $rows = $stmt->fetchAll();

    if (!$rows && $categoryKey !== 'campuran') {
        $stmt = $pdo->prepare("
            SELECT tag_text
            FROM video_hashtags
            WHERE category = 'campuran'
            ORDER BY RAND()
            LIMIT {$limit}
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll();
    }

    return array_map(fn($r) => $r['tag_text'], $rows);
}

/**
 * Build caption lengkap:
 * - caption random kategori
 * - info default
 * - 5 hashtag random kategori di bagian paling bawah
 */
function buildCaptionText(PDO $pdo, string $categoryKey): string
{
    $randomCaption = getRandomCaptionByCategory($pdo, $categoryKey);
    $defaultInfo   = "";

    $parts = [];

    if ($randomCaption && trim($randomCaption) !== '') {
        $parts[] = $randomCaption;
    }
    $parts[] = $defaultInfo;

    $hashtags = getRandomHashtags($pdo, $categoryKey, 5);
    if (!empty($hashtags)) {
        // gabung jadi satu baris, biarkan user input sudah mengandung #
        $parts[] = implode(' ', $hashtags);
    }

    return implode("\n\n", $parts);
}

/**
 * Keyboard pilih kategori (dipakai setelah /start atau saat belum pilih kategori).
 */
function buildCategoryKeyboard(): string
{
    global $CATEGORIES;

    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => 'Alquran',      'callback_data' => 'SET_CAT:alquran'],
                ['text' => 'Sandal JND',   'callback_data' => 'SET_CAT:jnd'],
            ],
            [
                ['text' => 'Sandal Akay',  'callback_data' => 'SET_CAT:akay'],
                ['text' => 'Parfum',       'callback_data' => 'SET_CAT:parfum'],
            ],
            [
                ['text' => 'Campuran',     'callback_data' => 'SET_CAT:campuran'],
            ],
        ],
    ]);
}

//////////////////////////
// BACA UPDATE TELEGRAM
//////////////////////////

$raw = file_get_contents('php://input');

// Saat dijalankan via CLI worker, payload masuk dari STDIN (bukan php://input).
if ((!$raw || trim($raw) === '') && PHP_SAPI === 'cli') {
    $raw = stream_get_contents(STDIN);
}

if (!$raw || trim($raw) === '') {
    exit;
}

$update = json_decode($raw, true);
if (!is_array($update)) {
    exit;
}

// Setiap request, sinkronkan folder video -> DB
syncVideosFromFolder($pdo, $VIDEO_DIR);

//////////////////////////
// HANDLER CALLBACK QUERY
//////////////////////////

if (!empty($update['callback_query'])) {
    $cb        = $update['callback_query'];
    $data      = $cb['data'] ?? '';
    $chatId    = (int)($cb['message']['chat']['id'] ?? 0);
    $messageId = (int)($cb['message']['message_id'] ?? 0);

    // Pilih kategori
    if (strpos($data, 'SET_CAT:') === 0 && $chatId !== 0) {
        $catKey = substr($data, strlen('SET_CAT:')); // contoh: alquran
        global $CATEGORIES;

        if (!isset($CATEGORIES[$catKey])) {
            tgRequest('answerCallbackQuery', [
                'callback_query_id' => $cb['id'],
                'text'              => 'Kategori tidak dikenal.',
                'show_alert'        => true,
            ]);
            exit;
        }

        setUserCategory($pdo, $chatId, $catKey);

        tgRequest('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'text'              => 'Kategori: ' . $CATEGORIES[$catKey],
            'show_alert'        => false,
        ]);

        // Kirim info + langsung video pertama
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => "Kategori video yang dipilih: *" . $CATEGORIES[$catKey] . "*\n\nMengambil video untukmu…",
            'parse_mode' => 'Markdown',
        ]);

        sendRandomVideoToChat($pdo, $chatId, $VIDEO_URL_PREFIX, $catKey);
        exit;
    }

    // NEXT VIDEO
    if ($data === 'NEXT_VIDEO' && $chatId !== 0) {
        tgRequest('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'text'              => 'Mengambil video berikutnya…',
            'show_alert'        => false,
        ]);

        sendRandomVideoToChat($pdo, $chatId, $VIDEO_URL_PREFIX, null);
        exit;
    }

    // NEW CAPTION
    if ($data === 'NEW_CAPTION' && $chatId !== 0 && $messageId !== 0) {
        tgRequest('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'text'              => 'Mengganti caption…',
            'show_alert'        => false,
        ]);

        // kategori berdasarkan user prefs
        $catKey = getUserCategory($pdo, $chatId) ?? 'campuran';
        $newCaption = buildCaptionText($pdo, $catKey);

        $res = tgRequest('editMessageText', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $newCaption,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => 'Next ▶️',       'callback_data' => 'NEXT_VIDEO'],
                        ['text' => 'Caption lain 🔁','callback_data' => 'NEW_CAPTION'],
                    ],
                ],
            ]),
        ]);

        if (!$res || empty($res['ok'])) {
            error_log('editMessageText error: ' . json_encode($res));
            tgRequest('sendMessage', [
                'chat_id' => $chatId,
                'text'    => 'Gagal mengganti caption. Coba lagi.',
            ]);
        }

        exit;
    }

    // callback lain tidak dikenal
    tgRequest('answerCallbackQuery', [
        'callback_query_id' => $cb['id'],
        'text'              => 'Aksi tidak dikenal.',
        'show_alert'        => false,
    ]);
    exit;
}

//////////////////////////
// HANDLER MESSAGE BIASA
//////////////////////////

$message = $update['message'] ?? $update['edited_message'] ?? null;
if (!$message) {
    exit;
}

$chatId = (int)($message['chat']['id'] ?? 0);
$text   = trim($message['text'] ?? '');

if ($chatId === 0) {
    exit;
}

if (strpos($text, '/') === 0) {
    $parts = explode(' ', $text, 2);
    $cmd   = strtolower($parts[0]);
    $cmd   = explode('@', $cmd)[0];
} else {
    $cmd = '';
}

// /start -> sapa + minta pilih kategori
if ($cmd === '/start') {
    $welcome = "Halo! 👋\n"
             . "Aku adalah bot kirim video berdasarkan kategori.\n\n"
             . "1. Pilih kategori video terlebih dahulu.\n"
             . "2. Bot akan kirim video random sesuai kategori.\n"
             . "3. Caption dan hashtag juga mengikuti kategori yang kamu pilih.\n\n"
             . "Silakan pilih kategori di bawah ini:";

    tgRequest('sendMessage', [
        'chat_id' => $chatId,
        'text'    => $welcome,
        'reply_markup' => buildCategoryKeyboard(),
    ]);
    exit;
}

// /next -> kirim video sesuai kategori user (jika belum pilih, minta pilih)
if ($cmd === '/next') {
    $catKey = getUserCategory($pdo, $chatId);

    if (!$catKey) {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => "Kamu belum memilih kategori video.\nSilakan pilih kategori terlebih dahulu:",
            'reply_markup' => buildCategoryKeyboard(),
        ]);
        exit;
    }

    sendRandomVideoToChat($pdo, $chatId, $VIDEO_URL_PREFIX, $catKey);
    exit;
}

// selain command dikenal → bantu info singkat
$help = "Perintah yang tersedia:\n"
      . "/start - Mulai dan pilih kategori video\n"
      . "/next  - Kirim video random berdasarkan kategori yang sudah dipilih";

tgRequest('sendMessage', [
    'chat_id' => $chatId,
    'text'    => $help,
]);
exit;

//////////////////////////
// FUNGSI KIRIM VIDEO
//////////////////////////

/**
 * Kirim 1 video random yang belum pernah dikirim ke chatId.
 * Kategori:
 *  - jika $categoryKey null -> ambil dari user_prefs (fallback 'campuran')
 *  - jika 'campuran' -> semua kategori
 */
function sendRandomVideoToChat(PDO $pdo, int $chatId, string $videoUrlPrefix, ?string $categoryKey = null): void
{
    global $CATEGORIES;

    if ($categoryKey === null) {
        $categoryKey = getUserCategory($pdo, $chatId) ?? 'campuran';
    }

    if (!isset($CATEGORIES[$categoryKey])) {
        $categoryKey = 'campuran';
    }

    // 1. Cari video yang belum pernah dikirim ke chat ini
    $video = getRandomUnsentVideo($pdo, $chatId, $categoryKey);

    if (!$video) {
        $msg = "Stok video untuk kategori *" . $CATEGORIES[$categoryKey] . "* sedang kosong.\n\n"
             . "Semua video kategori ini sudah pernah dikirim ke akun kamu, "
             . "atau belum ada video yang tersedia.\n"
             . "Silakan hubungi admin untuk menambah stok video baru.";
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => $msg,
            'parse_mode' => 'Markdown',
        ]);
        return;
    }

    $videoId  = (int)$video['id'];
    $filePath = $video['file_path'];               // contoh: videos/jnd/video1.mp4

    // 2. Path fisik di server
    $absolutePath = __DIR__ . '/' . ltrim($filePath, '/');

    if (!is_file($absolutePath)) {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => "Maaf, file video di server tidak ditemukan:\n" . $filePath,
        ]);
        return;
    }

    // 3. KIRIM VIDEO (tanpa caption)
    $res = tgRequest('sendVideo', [
        'chat_id' => $chatId,
        'video'   => new CURLFile($absolutePath),
    ]);

    if ($res && !empty($res['ok'])) {
        logVideoSent($pdo, $videoId, $chatId);
    } else {
        error_log("Telegram sendVideo error: " . json_encode($res) . " PATH=" . $absolutePath);

        $errText = "Maaf, terjadi error saat mengirim video.";
        if (isset($res['description']) && $res['description'] !== '') {
            $errText .= "\nDetail: " . $res['description'];
        }

        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => $errText,
        ]);
        return;
    }

    // 4. KIRIM CAPTION + 2 TOMBOL (Next & Caption lain)
    $captionText = buildCaptionText($pdo, $categoryKey);

    tgRequest('sendMessage', [
        'chat_id' => $chatId,
        'text'    => $captionText,
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Next ▶️',       'callback_data' => 'NEXT_VIDEO'],
                    ['text' => 'Caption lain 🔁','callback_data' => 'NEW_CAPTION'],
                ],
            ],
        ]),
    ]);
}
