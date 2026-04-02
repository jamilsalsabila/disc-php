<main class="hero-page">
  <section class="hero-card question-form-card">
    <p class="eyebrow">HR Console</p>
    <h1><?= h($form_title) ?></h1>
    <p class="subtitle">Bank soal mode one-for-all (Role: All). Mapping DISC per opsi dapat diatur sesuai rulebook.</p>

    <?php if (!empty($error_message)): ?>
      <div class="alert"><?= h($error_message) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash_message)): ?>
      <div class="alert <?= ($flash_type ?? 'success') === 'error' ? 'alert-danger' : 'alert-success' ?>">
        <?= h((string) $flash_message) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= h($action_url) ?>" class="identity-form">
      <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
      <input type="hidden" name="role_key" value="All">
      <label>
        Role
        <input type="text" value="All" disabled>
      </label>

      <label>
        Urutan Soal
        <input type="number" min="1" name="order" value="<?= h((string) ($values['order'] ?? '')) ?>" required>
      </label>

      <label>
        Opsi A
        <input type="text" name="option_a" value="<?= h($values['option_a'] ?? '') ?>" required>
      </label>
      <label>
        Mapping Opsi A
        <select name="disc_a" required>
          <?php foreach (['D','I','S','C'] as $d): ?>
            <option value="<?= h($d) ?>" <?= (($values['disc_a'] ?? 'D') === $d) ? 'selected' : '' ?>><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Opsi B
        <input type="text" name="option_b" value="<?= h($values['option_b'] ?? '') ?>" required>
      </label>
      <label>
        Mapping Opsi B
        <select name="disc_b" required>
          <?php foreach (['D','I','S','C'] as $d): ?>
            <option value="<?= h($d) ?>" <?= (($values['disc_b'] ?? 'I') === $d) ? 'selected' : '' ?>><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Opsi C
        <input type="text" name="option_c" value="<?= h($values['option_c'] ?? '') ?>" required>
      </label>
      <label>
        Mapping Opsi C
        <select name="disc_c" required>
          <?php foreach (['D','I','S','C'] as $d): ?>
            <option value="<?= h($d) ?>" <?= (($values['disc_c'] ?? 'S') === $d) ? 'selected' : '' ?>><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Opsi D
        <input type="text" name="option_d" value="<?= h($values['option_d'] ?? '') ?>" required>
      </label>
      <label>
        Mapping Opsi D
        <select name="disc_d" required>
          <?php foreach (['D','I','S','C'] as $d): ?>
            <option value="<?= h($d) ?>" <?= (($values['disc_d'] ?? 'C') === $d) ? 'selected' : '' ?>><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="check-inline">
        <input type="checkbox" name="is_active" value="1" <?= !empty($values['is_active']) ? 'checked' : '' ?>>
        Aktifkan soal ini
      </label>

      <div class="hr-actions">
        <button type="submit" class="btn-primary">Simpan</button>
        <?php if (!empty($is_create)): ?>
          <button type="submit" name="save_and_add" value="1" class="btn-secondary">Simpan & Tambah Lagi</button>
        <?php endif; ?>
        <a href="<?= h(route_path('/hr/questions')) ?>" class="btn-secondary">Batal</a>
      </div>
    </form>
  </section>
</main>
