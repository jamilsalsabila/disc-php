<main class="hr-page">
  <header class="hr-header">
    <div>
      <p class="eyebrow">HR Console</p>
      <h1>Kelola Soal Esai</h1>
      <p class="subtitle">Tambah, edit, hapus, dan aktif/nonaktifkan soal esai kandidat.</p>
    </div>
    <div class="hr-actions">
      <a href="<?= h(route_path('/hr/dashboard')) ?>" class="btn-secondary">Kembali ke Dashboard</a>
      <a href="<?= h(route_path('/hr/questions')) ?>" class="btn-secondary">Kelola Soal DISC</a>
      <a href="<?= h(route_path('/hr/essay-questions/new')) ?>" class="btn-primary">Tambah Soal Esai</a>
      <form method="post" action="<?= h(route_path('/hr/logout')) ?>" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
        <button type="submit" class="btn-secondary">Logout</button>
      </form>
    </div>
  </header>

  <?php if (!empty($flash_message)): ?>
    <div class="alert <?= ($flash_type ?? 'info') === 'error' ? 'alert-danger' : 'alert-success' ?>">
      <?= h((string) $flash_message) ?>
    </div>
  <?php endif; ?>

  <section class="table-card">
    <form method="get" action="<?= h(route_path('/hr/essay-questions')) ?>" class="filter-grid" style="grid-template-columns: 1fr auto auto;">
      <select name="group">
        <option value="">Semua kelompok role</option>
        <?php foreach (($essay_group_options ?? []) as $group): ?>
          <option value="<?= h($group) ?>" <?= (($group_filter ?? '') === $group) ? 'selected' : '' ?>><?= h($group) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-secondary">Filter</button>
      <a href="<?= h(route_path('/hr/essay-questions')) ?>" class="btn-secondary">Reset</a>
    </form>

    <div style="height:10px;"></div>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Kelompok</th>
          <th>Urutan</th>
          <th>Pertanyaan Esai</th>
          <th>Panduan Jawaban</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($essay_questions)): ?>
          <?php foreach ($essay_questions as $q): ?>
            <tr>
              <td>#<?= h((string) $q['id']) ?></td>
              <td><?= h((string) ($q['role_group'] ?? '-')) ?></td>
              <td><?= h((string) $q['order']) ?></td>
              <td><?= h($q['question_text']) ?></td>
              <td><?= h($q['guidance_text'] !== '' ? $q['guidance_text'] : '-') ?></td>
              <td><?= $q['is_active'] ? '<span class="badge-success">Aktif</span>' : '<span class="badge-muted">Nonaktif</span>' ?></td>
              <td>
                <a href="<?= h(route_path('/hr/essay-questions/' . $q['id'] . '/edit')) ?>" class="table-link btn-detail">Edit</a>
                <form method="post" action="<?= h(route_path('/hr/essay-questions/' . $q['id'] . '/toggle-active')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-secondary btn-xs"><?= $q['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
                <form method="post" action="<?= h(route_path('/hr/essay-questions/' . $q['id'] . '/delete')) ?>" class="inline-form" onsubmit="return confirm('Hapus soal esai ini?');">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-danger-outline btn-xs">Hapus</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="7">Belum ada soal esai.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>
</main>
