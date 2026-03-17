<main class="hr-page">
  <header class="hr-header">
    <div>
      <p class="eyebrow">HR Console</p>
      <h1>Kelola Soal DISC</h1>
      <p class="subtitle">Tambah, edit, hapus, dan aktif/nonaktifkan soal.</p>
    </div>
    <div class="hr-actions">
      <a href="<?= h(route_path('/hr/dashboard')) ?>" class="btn-secondary">Kembali ke Dashboard</a>
      <a href="<?= h(route_path('/hr/questions/new')) ?>" class="btn-primary">Tambah Soal</a>
      <form method="post" action="<?= h(route_path('/hr/logout')) ?>" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
        <button type="submit" class="btn-secondary">Logout</button>
      </form>
    </div>
  </header>

  <section class="table-card">
    <form method="get" action="<?= h(route_path('/hr/questions')) ?>" class="filter-grid" style="margin-bottom:12px;">
      <div class="subtitle" style="display:flex;align-items:center;padding:0 6px;">Filter soal berdasarkan role</div>
      <select name="role">
        <option value="">Semua role</option>
        <?php foreach (($role_options ?? []) as $role): ?>
          <option value="<?= h($role) ?>" <?= (($role_filter ?? '') === $role) ? 'selected' : '' ?>><?= h($role) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-secondary">Filter</button>
      <a href="<?= h(route_path('/hr/questions')) ?>" class="btn-secondary">Reset</a>
    </form>

    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Role</th>
          <th>Urutan</th>
          <th>Preview Opsi</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($question_bank)): ?>
          <?php foreach ($question_bank as $q): ?>
            <tr>
              <td>#<?= h((string) $q['id']) ?></td>
              <td><?= h($q['role_key']) ?></td>
              <td><?= h((string) $q['order']) ?></td>
              <td>
                <small>A. <?= h($q['optionA']) ?></small><br>
                <small>B. <?= h($q['optionB']) ?></small><br>
                <small>C. <?= h($q['optionC']) ?></small><br>
                <small>D. <?= h($q['optionD']) ?></small>
              </td>
              <td><?= $q['is_active'] ? '<span class="badge-success">Aktif</span>' : '<span class="badge-muted">Nonaktif</span>' ?></td>
              <td>
                <a href="<?= h(route_path('/hr/questions/' . $q['id'] . '/edit')) ?>" class="table-link btn-detail">Edit</a>
                <form method="post" action="<?= h(route_path('/hr/questions/' . $q['id'] . '/toggle-active')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-secondary btn-xs"><?= $q['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
                <form method="post" action="<?= h(route_path('/hr/questions/' . $q['id'] . '/delete')) ?>" class="inline-form" onsubmit="return confirm('Hapus soal ini? Tindakan ini tidak bisa dibatalkan.');">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-danger-outline btn-xs">Hapus</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="6">Belum ada soal.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>
</main>
