<?php
require_once __DIR__ . '/config.php';
$user   = require_role('admin', 'petugas');
$active = 'kelola';
$pageTitle = 'Kelola Laporan';

/* ------------------------ Aksi POST ------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $rs = db()->prepare('SELECT * FROM reports WHERE id = ?');
    $rs->execute([$id]);
    $rep = $rs->fetch();
    $back = 'kelola-laporan.php?' . http_build_query(array_filter(['id' => $id, 'q' => $_POST['back_q'] ?? '', 'status' => $_POST['back_status'] ?? '', 'page' => $_POST['back_page'] ?? '']));

    if (!$rep) {
        flash('error', 'Laporan tidak ditemukan.');
        redirect('kelola-laporan.php');
    }

    if (($_POST['do'] ?? '') === 'delete') {
        if ($user['role'] !== 'admin') {
            flash('error', 'Hanya admin yang dapat menghapus laporan.');
            redirect($back);
        }
        $files = db()->prepare('SELECT file_path FROM report_attachments WHERE report_id = ?');
        $files->execute([$id]);
        foreach ($files->fetchAll() as $f) {
            @unlink(UPLOAD_DIR . basename($f['file_path']));
        }
        db()->prepare('DELETE FROM reports WHERE id = ?')->execute([$id]);
        flash('success', 'Laporan #' . $rep['ticket_code'] . ' telah dihapus.');
        redirect('kelola-laporan.php');
    }

    // update status / disposisi / tanggapan
    $newStatus = $_POST['status'] ?? '';
    $agencyId  = (int)($_POST['agency_id'] ?? 0) ?: null;
    $message   = trim((string)($_POST['message'] ?? ''));
    if (!isset(STATUS_META[$newStatus]) || mb_strlen($message) > 300) {
        flash('error', 'Data tidak valid (status salah atau tanggapan melebihi 300 karakter).');
        redirect($back);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $resolved = $newStatus === 'selesai' ? ($rep['resolved_at'] ?: date('Y-m-d H:i:s')) : null;
        $assignee = $newStatus === 'proses' ? ($rep['assigned_to'] ?: $user['id']) : $rep['assigned_to'];
        $pdo->prepare('UPDATE reports SET status = ?, agency_id = ?, resolved_at = ?, assigned_to = ? WHERE id = ?')
            ->execute([$newStatus, $agencyId, $resolved, $assignee, $id]);

        if ($agencyId !== ($rep['agency_id'] !== null ? (int)$rep['agency_id'] : null)) {
            $an = $pdo->prepare('SELECT name FROM agencies WHERE id = ?'); $an->execute([$agencyId]);
            log_report($id, (int)$user['id'], 'disposisi', 'Didisposisikan ke ' . ($an->fetchColumn() ?: '-'));
        }
        if ($newStatus !== $rep['status']) {
            log_report($id, (int)$user['id'], 'status', 'Status diubah menjadi ' . STATUS_META[$newStatus]['label'] . ' oleh ' . $user['name']);
        }
        if ($message !== '') {
            $pdo->prepare('INSERT INTO report_responses (report_id, user_id, message) VALUES (?,?,?)')->execute([$id, $user['id'], $message]);
            log_report($id, (int)$user['id'], 'tanggapan', 'Tanggapan resmi ditambahkan.');
        }
        if ($newStatus === 'selesai') {
            foreach (save_uploads('done_photos') as $f) {
                $pdo->prepare("INSERT INTO report_attachments (report_id, file_path, file_type, file_size, kind) VALUES (?,?,?,?, 'penyelesaian')")
                    ->execute([$id, $f['path'], $f['type'], $f['size']]);
            }
        }
        if (!empty($_POST['notify']) && ($newStatus !== $rep['status'] || $message !== '')) {
            notify((int)$rep['user_id'], $id, 'Laporan #' . $rep['ticket_code'] . ' kini berstatus ' . STATUS_META[$newStatus]['label'] . ($message !== '' ? ': ' . $message : '.'));
        }
        $pdo->commit();
        flash('success', 'Perubahan laporan #' . $rep['ticket_code'] . ' berhasil disimpan.');
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash('error', 'Gagal menyimpan perubahan.');
    }
    redirect($back);
}

/* ------------------------ Filter & daftar ------------------------ */
$q      = trim((string)($_GET['q'] ?? ''));
$fStat  = $_GET['status'] ?? '';
$fCat   = (int)($_GET['cat'] ?? 0);
$fDist  = (int)($_GET['dist'] ?? 0);
$fMonth = $_GET['bulan'] ?? '';
$where  = ['1=1']; $par = [];
if ($q !== '')                      { $where[] = '(r.ticket_code LIKE ? OR r.title LIKE ? OR r.address LIKE ?)'; array_push($par, "%$q%", "%$q%", "%$q%"); }
if (isset(STATUS_META[$fStat]))     { $where[] = 'r.status = ?'; $par[] = $fStat; }
if ($fCat)                          { $where[] = 'r.category_id = ?'; $par[] = $fCat; }
if ($fDist)                         { $where[] = 'r.district_id = ?'; $par[] = $fDist; }
if (preg_match('/^\d{4}-\d{2}$/', $fMonth)) { $where[] = "DATE_FORMAT(r.created_at, '%Y-%m') = ?"; $par[] = $fMonth; }
$W = implode(' AND ', $where);

$perPage = 8;
$cnt = db()->prepare("SELECT COUNT(*) FROM reports r WHERE $W"); $cnt->execute($par);
$totalFiltered = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($totalFiltered / $perPage));
$page  = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$off   = ($page - 1) * $perPage;
$ls = db()->prepare("SELECT r.*, c.name cat_name, c.icon cat_icon, d.name district_name, u.name reporter, a.code agency_code
    FROM reports r JOIN categories c ON c.id = r.category_id JOIN users u ON u.id = r.user_id
    LEFT JOIN districts d ON d.id = r.district_id LEFT JOIN agencies a ON a.id = r.agency_id
    WHERE $W ORDER BY (r.status = 'pending') DESC, r.created_at DESC LIMIT $perPage OFFSET $off");
$ls->execute($par);
$rows = $ls->fetchAll();

$st = stats_overview();
$todayNew = (int)db()->query('SELECT COUNT(*) FROM reports WHERE created_at >= CURDATE() - INTERVAL 1 DAY')->fetchColumn();
$doneWeek = (int)db()->query("SELECT COUNT(*) FROM reports WHERE status='selesai' AND resolved_at >= CURDATE() - INTERVAL 7 DAY")->fetchColumn();
$urgent   = (int)db()->query("SELECT COUNT(*) FROM reports WHERE status='pending' AND impact_level='bahaya'")->fetchColumn();

// Detail terpilih
$selId = (int)($_GET['id'] ?? ($rows[0]['id'] ?? 0));
$sel = null;
if ($selId) {
    $s = db()->prepare("SELECT r.*, c.name cat_name, d.name district_name, u.name reporter, u.phone reporter_phone
        FROM reports r JOIN categories c ON c.id = r.category_id JOIN users u ON u.id = r.user_id
        LEFT JOIN districts d ON d.id = r.district_id WHERE r.id = ?");
    $s->execute([$selId]);
    $sel = $s->fetch() ?: null;
}
$selAtts = [];
if ($sel) {
    $a = db()->prepare('SELECT * FROM report_attachments WHERE report_id = ? ORDER BY id'); $a->execute([$sel['id']]); $selAtts = $a->fetchAll();
}
$qs = fn(array $over = []) => http_build_query(array_filter(array_merge(['q' => $q, 'status' => $fStat, 'cat' => $fCat, 'dist' => $fDist, 'bulan' => $fMonth, 'page' => $page], $over), fn($v) => $v !== '' && $v !== 0 && $v !== null));
$sel_cls = 'rounded bg-surface-container-low px-space-sm py-space-xs font-body-md text-body-md focus:outline-none focus:ring-2 focus:ring-primary';

include __DIR__ . '/includes/app_top.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md mb-space-lg">
  <div class="max-w-2xl">
    <div class="flex items-center gap-space-xs text-on-surface-variant font-label-sm text-label-sm mb-space-xs"><span>JagaRaksa</span><span class="material-symbols-outlined text-sm">chevron_right</span><span>Manajemen</span><span class="material-symbols-outlined text-sm">chevron_right</span><span class="text-primary font-semibold">Kelola Laporan</span></div>
    <h1 class="font-headline-xl text-headline-xl tracking-tight">Kelola Laporan Masyarakat</h1>
    <p class="font-body-md text-body-md text-on-surface-variant mt-space-2xs">Pantau, verifikasi keabsahan laporan, tetapkan dinas penanggung jawab, dan perbarui status pengerjaan fasilitas publik di seluruh wilayah kota.</p>
  </div>
  <button onclick="window.print()" class="inline-flex items-center gap-space-xs px-space-md py-space-sm rounded bg-surface-container-high font-label-lg text-label-lg hover:bg-surface-container-highest"><span class="material-symbols-outlined">print</span>Cetak Rekap Laporan</button>
</div>

<div class="grid grid-cols-2 xl:grid-cols-5 gap-gutter-sm mb-space-lg">
  <?php foreach ([
    ['Total Laporan', $st['total'], '+' . $todayNew . ' hari ini', '', 'text-primary'],
    ['Verifikasi Segera', $st['pending'], $urgent . ' membahayakan', 'border-l-4 border-amber-500', 'text-amber-800'],
    ['Dalam Pengerjaan', $st['proses'], 'Ditangani petugas', '', 'text-primary'],
    ['Selesai Minggu Ini', $doneWeek, 'Total ' . $st['selesai'] . ' selesai', '', 'text-emerald-700'],
    ['Ditolak / Invalid', $st['ditolak'], 'Duplikat / Swasta', '', 'text-error'],
  ] as [$l, $v, $s, $b, $c]): ?>
  <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md <?= $b ?>"><div class="font-label-md text-label-md text-on-surface-variant"><?= e($l) ?></div>
    <div class="flex items-end justify-between mt-space-xs"><span class="font-display-lg text-headline-xl"><?= number_format((int)$v) ?></span><span class="font-label-sm text-label-sm <?= $c ?>"><?= e($s) ?></span></div></div>
  <?php endforeach; ?>
</div>

<form method="get" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md mb-space-lg flex flex-col gap-space-sm">
  <div class="flex flex-wrap gap-space-sm">
    <div class="relative flex-1 min-w-[220px]"><span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
      <input name="q" value="<?= e($q) ?>" placeholder="Cari nomor tiket (JR-...), judul, lokasi" class="w-full pl-10 <?= $sel_cls ?> py-2"/></div>
    <select name="cat" class="<?= $sel_cls ?>"><option value="">Semua Kategori</option><?php foreach (categories() as $c): ?><option value="<?= $c['id'] ?>" <?= $fCat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select name="status" class="<?= $sel_cls ?>"><option value="">Semua Status</option><?php foreach (STATUS_META as $k => $m): ?><option value="<?= $k ?>" <?= $fStat === $k ? 'selected' : '' ?>><?= e($m['label']) ?></option><?php endforeach; ?></select>
    <select name="dist" class="<?= $sel_cls ?>"><option value="">Semua Wilayah</option><?php foreach (districts() as $d): ?><option value="<?= $d['id'] ?>" <?= $fDist === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
    <input type="month" name="bulan" value="<?= e($fMonth) ?>" class="<?= $sel_cls ?>"/>
  </div>
  <div class="flex items-center justify-between">
    <span class="font-label-sm text-label-sm text-on-surface-variant">Menampilkan <?= count($rows) ?> dari <?= number_format($totalFiltered) ?> laporan • <a href="kelola-laporan.php" class="text-primary font-semibold">Reset Filter</a></span>
    <button class="px-space-lg py-space-xs rounded bg-primary text-on-primary font-label-md text-label-md">Terapkan Filter</button>
  </div>
</form>

<div class="grid grid-cols-1 2xl:grid-cols-12 gap-gutter-sm items-start">
  <div class="2xl:col-span-7 bg-surface-container-lowest rounded-xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-left">
      <thead class="bg-surface-container-low font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wide"><tr><th class="p-space-md">Tiket &amp; Tgl</th><th class="p-space-md">Uraian Kasus</th><th class="p-space-md">Lokasi / Wilayah</th><th class="p-space-md">Status</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="4" class="p-space-xl text-center text-on-surface-variant">Tidak ada laporan yang cocok dengan filter.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $isSel = $sel && (int)$sel['id'] === (int)$r['id']; $isNew = strtotime($r['created_at']) > time() - 86400; ?>
        <tr class="<?= $isSel ? 'bg-primary-fixed/40' : 'hover:bg-surface-container-low' ?> cursor-pointer align-top" onclick="location='kelola-laporan.php?<?= $qs(['id' => $r['id']]) ?>'">
          <td class="p-space-md font-body-sm text-body-sm whitespace-nowrap"><a class="font-bold text-primary" href="kelola-laporan.php?<?= $qs(['id' => $r['id']]) ?>">#<?= e($r['ticket_code']) ?></a><br><span class="text-on-surface-variant"><?= e(format_tanggal($r['created_at'], true)) ?></span><?php if ($isNew): ?><br><span class="inline-block mt-1 px-1.5 rounded bg-error text-on-error font-label-sm text-label-sm">Baru</span><?php endif; ?></td>
          <td class="p-space-md"><div class="font-label-lg text-label-lg line-clamp-1"><?= e($r['title']) ?></div><div class="font-label-sm text-label-sm text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]"><?= e($r['cat_icon'] ?? 'report') ?></span><?= e($r['cat_name']) ?></div>
            <div class="font-body-sm text-body-sm text-on-surface-variant">Pelapor: <?= $r['is_anonymous'] ? 'Anonim (#USR-' . (int)$r['user_id'] . ')' : e($r['reporter']) ?></div></td>
          <td class="p-space-md font-body-sm text-body-sm"><?= e($r['address']) ?><br><span class="text-on-surface-variant">Kec. <?= e($r['district_name'] ?? '-') ?></span></td>
          <td class="p-space-md"><?= status_badge($r['status']) ?><?php if ($r['agency_code']): ?><div class="font-label-sm text-label-sm text-on-surface-variant mt-1">Dinas <?= e($r['agency_code']) ?></div><?php endif; ?><?php if ($r['impact_level'] === 'bahaya'): ?><div class="font-label-sm text-label-sm text-error mt-1">⚠ Bahaya</div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="flex items-center justify-between p-space-md bg-surface-container-low/50">
      <span class="font-body-sm text-body-sm text-on-surface-variant">Halaman <?= $page ?> dari <?= $pages ?></span>
      <div class="flex items-center gap-1">
        <?php if ($page > 1): ?><a class="px-3 py-1 rounded hover:bg-surface-container" href="?<?= $qs(['page' => $page - 1, 'id' => '']) ?>">‹</a><?php endif; ?>
        <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?><a class="px-3 py-1 rounded <?= $p === $page ? 'bg-primary text-on-primary' : 'hover:bg-surface-container' ?>" href="?<?= $qs(['page' => $p, 'id' => '']) ?>"><?= $p ?></a><?php endfor; ?>
        <?php if ($page < $pages): ?><a class="px-3 py-1 rounded hover:bg-surface-container" href="?<?= $qs(['page' => $page + 1, 'id' => '']) ?>">›</a><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="2xl:col-span-5 bg-surface-container-lowest rounded-xl shadow-md p-space-lg 2xl:sticky 2xl:top-24">
  <?php if (!$sel): ?><p class="text-on-surface-variant">Pilih laporan pada tabel untuk melihat detail.</p>
  <?php else: ?>
    <div class="flex items-start justify-between gap-2 mb-space-xs"><h3 class="font-headline-md text-headline-md">Detail Laporan #<?= e($sel['ticket_code']) ?></h3><?= status_badge($sel['status']) ?></div>
    <p class="font-body-sm text-body-sm text-on-surface-variant mb-space-sm">Diterima: <?= e(format_tanggal($sel['created_at'], true)) ?></p>
    <div class="grid grid-cols-2 gap-space-sm mb-space-sm">
      <?php $ph = array_values(array_filter($selAtts, fn($x) => $x['kind'] === 'bukti'))[0] ?? null; ?>
      <div class="relative h-32 rounded-lg overflow-hidden bg-surface-container"><?php if ($ph): ?><a href="<?= e(UPLOAD_URL . $ph['file_path']) ?>" target="_blank"><img class="w-full h-full object-cover" src="<?= e(UPLOAD_URL . $ph['file_path']) ?>" alt="Foto"/></a><?php else: ?><div class="w-full h-full flex items-center justify-center text-outline"><span class="material-symbols-outlined text-4xl">image</span></div><?php endif; ?>
        <span class="absolute bottom-1 left-1 px-2 rounded bg-black/60 text-white font-label-sm text-label-sm">Foto Pelapor (<?= count($selAtts) ?>)</span></div>
      <div id="selMap" class="h-32 rounded-lg overflow-hidden"></div>
    </div>
    <div class="p-space-sm rounded-lg bg-surface-container-low mb-space-sm">
      <div class="font-label-sm text-label-sm text-on-surface-variant uppercase mb-1">Uraian Aduan Warga:</div>
      <div class="font-label-lg text-label-lg mb-1"><?= e($sel['title']) ?></div>
      <p class="font-body-sm text-body-sm whitespace-pre-line">“<?= e($sel['description']) ?>”</p>
      <div class="flex justify-between mt-space-xs font-body-sm text-body-sm text-on-surface-variant"><span class="flex items-center gap-1"><span class="material-symbols-outlined text-base">person</span><?= e($sel['reporter']) ?><?= $sel['reporter_phone'] ? ' (' . e($sel['reporter_phone']) . ')' : '' ?><?= $sel['is_anonymous'] ? ' • anonim publik' : '' ?></span></div>
    </div>
    <form method="post" enctype="multipart/form-data" class="flex flex-col gap-space-sm">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$sel['id'] ?>"><input type="hidden" name="back_q" value="<?= e($q) ?>"><input type="hidden" name="back_status" value="<?= e($fStat) ?>"><input type="hidden" name="back_page" value="<?= $page ?>">
      <div class="grid grid-cols-2 gap-space-sm">
        <label class="flex flex-col gap-1 font-label-sm text-label-sm">Dinas Penanggung Jawab (OPD)
          <select name="agency_id" class="<?= $sel_cls ?>"><option value="">- Belum disposisi -</option><?php foreach (agencies() as $a): ?><option value="<?= $a['id'] ?>" <?= (int)$sel['agency_id'] === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></label>
        <label class="flex flex-col gap-1 font-label-sm text-label-sm">Ubah Status Tiket
          <select name="status" id="statusSel" class="<?= $sel_cls ?>"><?php foreach (STATUS_META as $k => $m): ?><option value="<?= $k ?>" <?= $sel['status'] === $k ? 'selected' : '' ?>><?= e($m['label']) ?></option><?php endforeach; ?></select></label>
      </div>
      <label class="flex flex-col gap-1 font-label-sm text-label-sm"><span class="flex justify-between">Tanggapan Resmi untuk Pelapor<span id="mc">0/300</span></span>
        <textarea name="message" maxlength="300" rows="3" oninput="document.getElementById('mc').textContent=this.value.length+'/300'" class="<?= $sel_cls ?> resize-none" placeholder="Tim teknis akan meninjau lokasi besok pukul 13.00 WIB..."></textarea></label>
      <label id="doneBox" class="<?= $sel['status'] === 'selesai' ? '' : 'hidden' ?> flex flex-col gap-1 font-label-sm text-label-sm">Foto bukti penyelesaian (opsional, JPG/PNG)
        <input type="file" name="done_photos[]" accept="image/jpeg,image/png" multiple class="font-body-sm"/></label>
      <label class="flex items-center gap-2 font-body-sm text-body-sm"><input type="checkbox" name="notify" value="1" checked class="accent-primary w-4 h-4"/>Kirim notifikasi otomatis ke pelapor</label>
      <div class="flex items-center justify-between gap-2 pt-1">
        <?php if ($user['role'] === 'admin'): ?><button name="do" value="delete" onclick="return confirm('Hapus laporan ini beserta foto dan riwayatnya secara permanen?')" class="flex items-center gap-1 text-error font-label-md text-label-md"><span class="material-symbols-outlined text-lg">delete</span>Hapus</button><?php else: ?><span></span><?php endif; ?>
        <div class="flex gap-2"><a href="kelola-laporan.php?<?= $qs(['id' => '']) ?>" class="px-space-md py-space-xs rounded bg-surface-container font-label-md text-label-md">Batal</a>
          <button name="do" value="save" class="inline-flex items-center gap-1 px-space-md py-space-xs rounded bg-primary text-on-primary font-label-md text-label-md"><span class="material-symbols-outlined text-lg">done_all</span>Simpan &amp; Kirim</button></div>
      </div>
    </form>
    <script>
      document.getElementById('statusSel').addEventListener('change',e=>document.getElementById('doneBox').classList.toggle('hidden',e.target.value!=='selesai'));
      (function(){const m=L.map('selMap',{zoomControl:false,scrollWheelZoom:false,dragging:false}).setView([<?= (float)$sel['latitude'] ?>,<?= (float)$sel['longitude'] ?>],15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(m);
        L.circleMarker([<?= (float)$sel['latitude'] ?>,<?= (float)$sel['longitude'] ?>],{radius:9,color:'#fff',weight:2,fillColor:'<?= STATUS_META[$sel['status']]['color'] ?>',fillOpacity:1}).addTo(m);})();
    </script>
  <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/app_bottom.php'; ?>
