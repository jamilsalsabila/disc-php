<main class="hero-page">
  <section class="hero-card question-form-card">
    <p class="eyebrow">HR Console</p>
    <h1><?= h($form_title) ?></h1>
    <p class="subtitle">Pemetaan DISC tetap: A=D, B=I, C=S, D=C.</p>

    <?php if (!empty($error_message)): ?>
      <div class="alert"><?= h($error_message) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= h($action_url) ?>" class="identity-form">
      <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">

      <label>
        Role
        <select name="role_key" required>
          <?php foreach (($role_options ?? []) as $role): ?>
            <option value="<?= h($role) ?>" <?= (($values['role_key'] ?? '') === $role) ? 'selected' : '' ?>><?= h($role) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Urutan Soal
        <input type="number" min="1" name="order" value="<?= h((string) ($values['order'] ?? '')) ?>" required>
      </label>

      <label>
        Opsi A (Dominance)
        <input type="text" name="option_a" value="<?= h($values['option_a'] ?? '') ?>" required>
      </label>

      <label>
        Opsi B (Influence)
        <input type="text" name="option_b" value="<?= h($values['option_b'] ?? '') ?>" required>
      </label>

      <label>
        Opsi C (Steadiness)
        <input type="text" name="option_c" value="<?= h($values['option_c'] ?? '') ?>" required>
      </label>

      <label>
        Opsi D (Conscientiousness)
        <input type="text" name="option_d" value="<?= h($values['option_d'] ?? '') ?>" required>
      </label>

      <label class="check-inline">
        <input type="checkbox" name="is_active" value="1" <?= !empty($values['is_active']) ? 'checked' : '' ?>>
        Aktifkan soal ini
      </label>

      <div class="hr-actions">
        <button type="submit" class="btn-primary">Simpan</button>
        <a href="<?= h(route_path('/hr/questions')) ?>" class="btn-secondary">Batal</a>
      </div>
    </form>
  </section>
</main>
