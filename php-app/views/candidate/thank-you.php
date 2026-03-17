<main class="simple-page">
  <section class="simple-card">
    <h1>Terima Kasih</h1>
    <p>Jawaban Anda sudah tersimpan di sistem.</p>

    <?php if (!empty($candidate)): ?>
      <div class="result-box">
        <p><strong>Nama:</strong> <?= h($candidate['full_name']) ?></p>
        <p><strong>Role Dipilih:</strong> <?= h($candidate['selected_role']) ?></p>
        <p><strong>Status:</strong> <?= h($candidate['status']) ?></p>
      </div>
    <?php endif; ?>

    <a href="<?= h(route_path('/')) ?>" class="btn-secondary">Kembali ke Halaman Awal</a>
  </section>
</main>
