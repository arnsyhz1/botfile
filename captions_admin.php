<?php
/*
======================================================
 ADMIN CAPTION & HASHTAG VIDEO BOT (VERSI FULL)
------------------------------------------------------
FITUR:
- Login sederhana pakai password.
- Tambah / edit / hapus caption per kategori.
- Tambah / edit / hapus hashtag per kategori.
- Bulk insert caption (satu kategori, banyak baris).
- Bulk insert hashtag (satu kategori, banyak baris).
======================================================
*/

session_start();

//////////////////////////
// KONFIGURASI
//////////////////////////

$ADMIN_PASSWORD = '@Akay2k25!'; // GANTI

// Konfigurasi database (HARUS sama dengan bot.php)
$dbHost = 'localhost';
$dbName = 'u386246510_akayfile';    // sama dengan bot.php
$dbUser = 'u386246510_akayfile';      // ganti
$dbPass = '@Akay2k25!';      // ganti

// DEFINISI KATEGORI (key di DB, label untuk admin)
$CATEGORIES = [
    'alquran'   => 'Alquran',
    'jnd'       => 'Sandal JND',
    'akay'      => 'Sandal Akay',
    'parfum'    => 'Parfum',
    'campuran'  => 'Campuran (umum)',
];

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
    die('Koneksi database gagal: ' . htmlspecialchars($e->getMessage()));
}

//////////////////////////
// LOGIN SEDERHANA
//////////////////////////

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $pass = $_POST['password'] ?? '';
    if ($pass === $ADMIN_PASSWORD) {
        $_SESSION['caption_admin_logged'] = true;
        header('Location: captions_admin.php');
        exit;
    } else {
        $login_error = 'Password salah.';
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['caption_admin_logged']);
    session_destroy();
    header('Location: captions_admin.php');
    exit;
}

$logged = !empty($_SESSION['caption_admin_logged']);

//////////////////////////
// HANDLE AKSI CRUD
//////////////////////////

$error_msg = '';

if ($logged) {
    $action = $_POST['action'] ?? null;

    // Tambah caption satuan
    if ($action === 'add_caption') {
        $captionText = trim($_POST['caption_text'] ?? '');
        $categoryKey = $_POST['caption_category'] ?? 'campuran';

        if ($captionText === '') {
            $error_msg = 'Caption tidak boleh kosong.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO video_captions (caption_text, category)
                VALUES (:c, :cat)
            ");
            $stmt->execute([
                ':c'   => $captionText,
                ':cat' => $categoryKey,
            ]);
            header('Location: captions_admin.php?added_caption=1');
            exit;
        }
    }

    // Update caption
    if ($action === 'update_caption') {
        $captionId   = (int)($_POST['caption_id'] ?? 0);
        $captionText = trim($_POST['caption_text'] ?? '');
        $categoryKey = $_POST['caption_category'] ?? 'campuran';

        if ($captionId <= 0 || $captionText === '') {
            $error_msg = 'Data caption tidak lengkap untuk update.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE video_captions
                SET caption_text = :c, category = :cat
                WHERE id = :id
            ");
            $stmt->execute([
                ':c'   => $captionText,
                ':cat' => $categoryKey,
                ':id'  => $captionId,
            ]);
            header('Location: captions_admin.php?updated_caption=1');
            exit;
        }
    }

    // Bulk caption (tiap baris = 1 caption)
    if ($action === 'bulk_caption') {
        $bulkText    = $_POST['bulk_caption_texts'] ?? '';
        $categoryKey = $_POST['bulk_caption_category'] ?? 'campuran';

        $lines = preg_split('/\R/u', $bulkText);
        $count = 0;
        $stmt  = $pdo->prepare("
            INSERT INTO video_captions (caption_text, category)
            VALUES (:c, :cat)
        ");

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $stmt->execute([
                ':c'   => $line,
                ':cat' => $categoryKey,
            ]);
            $count++;
        }

        header('Location: captions_admin.php?bulk_caption=' . $count);
        exit;
    }

    // Hapus caption
    if (isset($_GET['delete_caption'])) {
        $id = (int)$_GET['delete_caption'];
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM video_captions WHERE id = :id");
            $stmt->execute([':id' => $id]);
            header('Location: captions_admin.php?deleted_caption=1');
            exit;
        }
    }

    // Tambah hashtag satuan
    if ($action === 'add_hashtag') {
        $tagText     = trim($_POST['tag_text'] ?? '');
        $categoryKey = $_POST['hashtag_category'] ?? 'campuran';

        if ($tagText === '') {
            $error_msg = 'Hashtag tidak boleh kosong.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO video_hashtags (tag_text, category)
                VALUES (:t, :cat)
            ");
            $stmt->execute([
                ':t'   => $tagText,
                ':cat' => $categoryKey,
            ]);
            header('Location: captions_admin.php?added_hashtag=1');
            exit;
        }
    }

    // Update hashtag
    if ($action === 'update_hashtag') {
        $tagId       = (int)($_POST['hashtag_id'] ?? 0);
        $tagText     = trim($_POST['tag_text'] ?? '');
        $categoryKey = $_POST['hashtag_category'] ?? 'campuran';

        if ($tagId <= 0 || $tagText === '') {
            $error_msg = 'Data hashtag tidak lengkap untuk update.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE video_hashtags
                SET tag_text = :t, category = :cat
                WHERE id = :id
            ");
            $stmt->execute([
                ':t'   => $tagText,
                ':cat' => $categoryKey,
                ':id'  => $tagId,
            ]);
            header('Location: captions_admin.php?updated_hashtag=1');
            exit;
        }
    }

    // Bulk hashtag (tiap baris = 1 hashtag)
    if ($action === 'bulk_hashtag') {
        $bulkText    = $_POST['bulk_hashtag_texts'] ?? '';
        $categoryKey = $_POST['bulk_hashtag_category'] ?? 'campuran';

        $lines = preg_split('/\R/u', $bulkText);
        $count = 0;
        $stmt  = $pdo->prepare("
            INSERT INTO video_hashtags (tag_text, category)
            VALUES (:t, :cat)
        ");

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $stmt->execute([
                ':t'   => $line,
                ':cat' => $categoryKey,
            ]);
            $count++;
        }

        header('Location: captions_admin.php?bulk_hashtag=' . $count);
        exit;
    }

    // Hapus hashtag
    if (isset($_GET['delete_hashtag'])) {
        $id = (int)$_GET['delete_hashtag'];
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM video_hashtags WHERE id = :id");
            $stmt->execute([':id' => $id]);
            header('Location: captions_admin.php?deleted_hashtag=1');
            exit;
        }
    }

    // Ambil daftar caption
    $stmt = $pdo->query("
        SELECT id, caption_text, category, created_at
        FROM video_captions
        ORDER BY created_at DESC, id DESC
    ");
    $captions = $stmt->fetchAll();

    // Ambil daftar hashtag
    $stmt = $pdo->query("
        SELECT id, tag_text, category, created_at
        FROM video_hashtags
        ORDER BY created_at DESC, id DESC
    ");
    $hashtags = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Admin Caption & Hashtag Video</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="captions_admin.css">
</head>
<body>
  <div class="admin-shell">
    <div class="card">
      <?php if (!$logged): ?>
        <div class="fade-in">
          <h1>Caption & Hashtag Video Bot</h1>
          <p class="subtitle">Masukkan password admin untuk mengelola caption dan hashtag.</p>
          <?php if (!empty($login_error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endif; ?>
          <form method="post" class="form-single">
            <input type="hidden" name="action" value="login">
            <label class="field">
              <span>Password admin</span>
              <input type="password" name="password" required>
            </label>
            <button type="submit" class="btn-primary">Masuk</button>
          </form>
        </div>
      <?php else: ?>
        <header class="top-row fade-down">
          <div>
            <h1>Caption & Hashtag Video Bot</h1>
            <p class="subtitle">
              Caption dan hashtag di sini akan dipakai bot sesuai kategori video yang dipilih user.
            </p>
          </div>
          <div class="top-right">
            <div class="pill">
              Caption: <?php echo isset($captions) ? count($captions) : 0; ?> |
              Hashtag: <?php echo isset($hashtags) ? count($hashtags) : 0; ?>
            </div>
            <a href="?logout=1" class="link-logout">Logout</a>
          </div>
        </header>

        <?php if (!empty($_GET['added_caption'])): ?>
          <div class="alert alert-success">Caption baru berhasil disimpan.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['updated_caption'])): ?>
          <div class="alert alert-success">Caption berhasil diupdate.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['deleted_caption'])): ?>
          <div class="alert alert-success">Caption berhasil dihapus.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['bulk_caption'])): ?>
          <div class="alert alert-success">
            Berhasil menyimpan <?php echo (int)$_GET['bulk_caption']; ?> caption secara massal.
          </div>
        <?php endif; ?>

        <?php if (!empty($_GET['added_hashtag'])): ?>
          <div class="alert alert-success">Hashtag baru berhasil disimpan.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['updated_hashtag'])): ?>
          <div class="alert alert-success">Hashtag berhasil diupdate.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['deleted_hashtag'])): ?>
          <div class="alert alert-success">Hashtag berhasil dihapus.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['bulk_hashtag'])): ?>
          <div class="alert alert-success">
            Berhasil menyimpan <?php echo (int)$_GET['bulk_hashtag']; ?> hashtag secara massal.
          </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
          <div class="alert alert-error"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="grid">
          <!-- PANEL CAPTION -->
          <section class="panel fade-in">
            <h2>Kelola Caption</h2>
            <p class="panel-subtitle">
              Mode tambah / edit caption per kategori. Untuk bulk, isi banyak baris sekaligus.
            </p>

            <!-- Form caption single (add / edit) -->
            <form method="post" class="form-block" id="formCaption">
              <input type="hidden" name="action" id="caption_action" value="add_caption">
              <input type="hidden" name="caption_id" id="caption_id" value="">
              <div class="form-row">
                <label class="field w-40">
                  <span>Kategori caption</span>
                  <select name="caption_category" id="caption_category">
                    <?php foreach ($CATEGORIES as $key => $label): ?>
                      <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <div class="field w-60 label-mode">
                  <span>Mode:</span>
                  <span id="caption_mode_label" class="mode-label">Tambah caption baru</span>
                </div>
              </div>
              <label class="field">
                <span>Teks caption</span>
                <textarea name="caption_text" id="caption_text"
                  placeholder="Tulis caption lengkap. Jika ingin baris baru gunakan Enter."></textarea>
              </label>
              <div class="form-actions">
                <button type="submit" class="btn-primary" id="caption_submit_btn">Simpan caption</button>
                <button type="button" class="btn-ghost" id="caption_reset_btn">Batal edit</button>
              </div>
            </form>

            <!-- Bulk caption -->
            <details class="bulk-block">
              <summary>Tambah caption secara massal</summary>
              <p class="bulk-note">
                Pilih kategori lalu isi banyak baris sekaligus. Satu baris = satu caption.
              </p>
              <form method="post" class="form-block">
                <input type="hidden" name="action" value="bulk_caption">
                <label class="field">
                  <span>Kategori caption massal</span>
                  <select name="bulk_caption_category">
                    <?php foreach ($CATEGORIES as $key => $label): ?>
                      <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="field">
                  <span>Daftar caption (satu per baris)</span>
                  <textarea name="bulk_caption_texts"
                    placeholder="Contoh:
Caption 1...
Caption 2...
Caption 3..."></textarea>
                </label>
                <button type="submit" class="btn-secondary">Simpan caption massal</button>
              </form>
            </details>

            <h3 class="list-title">Daftar Caption</h3>
            <?php if (empty($captions)): ?>
              <p class="empty-note">Belum ada caption. Tambah minimal 1 caption per kategori yang digunakan.</p>
            <?php else: ?>
              <div class="table-wrapper">
                <table>
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Kategori</th>
                      <th>Caption</th>
                      <th>Dibuat</th>
                      <th>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($captions as $c): ?>
                      <tr>
                        <td><?php echo (int)$c['id']; ?></td>
                        <td>
                          <?php
                            $ck = $c['category'];
                            echo htmlspecialchars($CATEGORIES[$ck] ?? $ck, ENT_QUOTES, 'UTF-8');
                          ?>
                        </td>
                        <td class="cell-text">
                          <?php echo nl2br(htmlspecialchars($c['caption_text'], ENT_QUOTES, 'UTF-8')); ?>
                        </td>
                        <td><?php echo htmlspecialchars($c['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="cell-actions">
                          <button
                            type="button"
                            class="btn-chip btn-edit-caption"
                            data-id="<?php echo (int)$c['id']; ?>"
                            data-cat="<?php echo htmlspecialchars($c['category'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-text="<?php echo htmlspecialchars($c['caption_text'], ENT_QUOTES, 'UTF-8'); ?>"
                          >Edit</button>
                          <a class="btn-chip btn-danger"
                             href="?delete_caption=<?php echo (int)$c['id']; ?>"
                             onclick="return confirm('Hapus caption ini?');">Hapus</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <!-- PANEL HASHTAG -->
          <section class="panel fade-in delay-1">
            <h2>Kelola Hashtag</h2>
            <p class="panel-subtitle">
              Hashtag akan diambil max 5 random berdasarkan kategori caption/video.
            </p>

            <!-- Form hashtag single (add / edit) -->
            <form method="post" class="form-block" id="formHashtag">
              <input type="hidden" name="action" id="hashtag_action" value="add_hashtag">
              <input type="hidden" name="hashtag_id" id="hashtag_id" value="">
              <div class="form-row">
                <label class="field w-40">
                  <span>Kategori hashtag</span>
                  <select name="hashtag_category" id="hashtag_category">
                    <?php foreach ($CATEGORIES as $key => $label): ?>
                      <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <div class="field w-60 label-mode">
                  <span>Mode:</span>
                  <span id="hashtag_mode_label" class="mode-label">Tambah hashtag baru</span>
                </div>
              </div>
              <label class="field">
                <span>Teks hashtag</span>
                <input type="text" name="tag_text" id="tag_text"
                       placeholder="Contoh: #SandalJND atau #OOTDsimple">
              </label>
              <div class="form-actions">
                <button type="submit" class="btn-primary" id="hashtag_submit_btn">Simpan hashtag</button>
                <button type="button" class="btn-ghost" id="hashtag_reset_btn">Batal edit</button>
              </div>
            </form>

            <!-- Bulk hashtag -->
            <details class="bulk-block">
              <summary>Tambah hashtag secara massal</summary>
              <p class="bulk-note">
                Satu baris = satu hashtag. Boleh pakai tanda # atau tidak.
              </p>
              <form method="post" class="form-block">
                <input type="hidden" name="action" value="bulk_hashtag">
                <label class="field">
                  <span>Kategori hashtag massal</span>
                  <select name="bulk_hashtag_category">
                    <?php foreach ($CATEGORIES as $key => $label): ?>
                      <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="field">
                  <span>Daftar hashtag (satu per baris)</span>
                  <textarea name="bulk_hashtag_texts"
                    placeholder="Contoh:
#ootd
#sandal
#parfumpria"></textarea>
                </label>
                <button type="submit" class="btn-secondary">Simpan hashtag massal</button>
              </form>
            </details>

            <h3 class="list-title">Daftar Hashtag</h3>
            <?php if (empty($hashtags)): ?>
              <p class="empty-note">Belum ada hashtag. Tambah beberapa hashtag untuk tiap kategori.</p>
            <?php else: ?>
              <div class="table-wrapper">
                <table>
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Kategori</th>
                      <th>Hashtag</th>
                      <th>Dibuat</th>
                      <th>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($hashtags as $h): ?>
                      <tr>
                        <td><?php echo (int)$h['id']; ?></td>
                        <td>
                          <?php
                            $hk = $h['category'];
                            echo htmlspecialchars($CATEGORIES[$hk] ?? $hk, ENT_QUOTES, 'UTF-8');
                          ?>
                        </td>
                        <td class="cell-text">
                          <?php echo htmlspecialchars($h['tag_text'], ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td><?php echo htmlspecialchars($h['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="cell-actions">
                          <button
                            type="button"
                            class="btn-chip btn-edit-hashtag"
                            data-id="<?php echo (int)$h['id']; ?>"
                            data-cat="<?php echo htmlspecialchars($h['category'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-text="<?php echo htmlspecialchars($h['tag_text'], ENT_QUOTES, 'UTF-8'); ?>"
                          >Edit</button>
                          <a class="btn-chip btn-danger"
                             href="?delete_hashtag=<?php echo (int)$h['id']; ?>"
                             onclick="return confirm('Hapus hashtag ini?');">Hapus</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php if ($logged): ?>
<script>
// Helper: reset form caption ke mode tambah
function resetCaptionForm() {
  document.getElementById('caption_action').value = 'add_caption';
  document.getElementById('caption_id').value = '';
  document.getElementById('caption_text').value = '';
  document.getElementById('caption_mode_label').textContent = 'Tambah caption baru';
  document.getElementById('caption_submit_btn').textContent = 'Simpan caption';
}

// Helper: reset form hashtag ke mode tambah
function resetHashtagForm() {
  document.getElementById('hashtag_action').value = 'add_hashtag';
  document.getElementById('hashtag_id').value = '';
  document.getElementById('tag_text').value = '';
  document.getElementById('hashtag_mode_label').textContent = 'Tambah hashtag baru';
  document.getElementById('hashtag_submit_btn').textContent = 'Simpan hashtag';
}

document.getElementById('caption_reset_btn').addEventListener('click', function () {
  resetCaptionForm();
});

document.getElementById('hashtag_reset_btn').addEventListener('click', function () {
  resetHashtagForm();
});

// Tombol edit caption
document.querySelectorAll('.btn-edit-caption').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var id  = this.dataset.id;
    var cat = this.dataset.cat;
    var txt = this.dataset.text;

    document.getElementById('caption_action').value = 'update_caption';
    document.getElementById('caption_id').value = id;
    document.getElementById('caption_category').value = cat;
    document.getElementById('caption_text').value = txt;
    document.getElementById('caption_mode_label').textContent = 'Edit caption #' + id;
    document.getElementById('caption_submit_btn').textContent = 'Update caption';

    // scroll smooth ke form
    document.getElementById('formCaption').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

// Tombol edit hashtag
document.querySelectorAll('.btn-edit-hashtag').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var id  = this.dataset.id;
    var cat = this.dataset.cat;
    var txt = this.dataset.text;

    document.getElementById('hashtag_action').value = 'update_hashtag';
    document.getElementById('hashtag_id').value = id;
    document.getElementById('hashtag_category').value = cat;
    document.getElementById('tag_text').value = txt;
    document.getElementById('hashtag_mode_label').textContent = 'Edit hashtag #' + id;
    document.getElementById('hashtag_submit_btn').textContent = 'Update hashtag';

    document.getElementById('formHashtag').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});
</script>
<?php endif; ?>
</body>
</html>
