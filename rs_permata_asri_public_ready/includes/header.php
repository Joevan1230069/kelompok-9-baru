<?php
/** @var array $user */
$pageTitle = $pageTitle ?? 'SIMRS';
$activeFile = basename($_SERVER['PHP_SELF']);
$menus = sidebar_menus((int) $user['role_id']);
$initials = strtoupper(substr($user['full_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> - Rumah Sakit Permata Asri</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <aside class="sidebar">
    <div class="sidebar-brand">
      <img src="assets/img/logo.svg" class="logo-image" alt="Logo RS Permata Asri">
      <div>
        <strong>Permata Asri</strong>
        <small>Sistem Informasi Rumah Sakit</small>
      </div>
    </div>

    <div class="role-card">
      <b><?= e($user['full_name']) ?></b>
      <span><?= e($user['employee_code']) ?> · <?= e($user['role_name']) ?></span>
      <span><?= e($user['unit_name'] ?? '-') ?></span>
    </div>

    <?php $section = null; ?>
    <?php foreach ($menus as $menu): ?>
      <?php if ($section !== $menu['section_name']): $section = $menu['section_name']; ?>
        <div class="sidebar-menu-title"><?= e($section) ?></div>
      <?php endif; ?>
      <a class="sidebar-link <?= $activeFile === $menu['page_file'] ? 'active' : '' ?>" href="<?= e($menu['page_file']) ?>">
        <span class="ico"><?= e($menu['icon']) ?></span>
        <span><?= e($menu['label']) ?></span>
      </a>
    <?php endforeach; ?>

    <div class="sidebar-menu-title">AKUN</div>
    <a href="#" class="sidebar-link logout-link" data-open-logout>
      <span class="ico">↪</span><span>Logout</span>
    </a>
  </aside>

  <div class="content-shell">
    <header class="topbar">
      <div>
        <strong><?= e($pageTitle) ?></strong>
        <div class="note">Rumah Sakit Permata Asri</div>
      </div>
      <div class="user-chip">
        <div>
          <strong><?= e($user['full_name']) ?></strong>
          <span><?= e($user['profession']) ?> · <?= e($user['employee_code']) ?></span>
        </div>
        <div class="avatar"><?= e($initials) ?></div>
      </div>
    </header>
    <main class="page-wrap">
      <?php if ($message = flash('success')): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
      <?php endif; ?>
      <?php if ($message = flash('error')): ?>
        <div class="alert alert-error"><?= e($message) ?></div>
      <?php endif; ?>
