<?php

function site_customization_defaults(): array
{
    return [
        'brand_name' => 'Six Origins',
        'brand_tagline' => 'Cafe',
        'logo_path' => 'images/logos.png',
        'login_image_path' => 'images/logback.jpeg',
        'register_image_path' => 'images/logback.jpeg',
        'admin_register_image_path' => 'images/logback.jpeg',
        'loading_style' => 'rings',
        'primary_color' => '#C6453E',
        'secondary_color' => '#5E1F13',
        'accent_color' => '#664C47',
        'page_background' => '#FFFAF5',
        'surface_color' => '#FFFFFF',
        'text_color' => '#3D2B27',
        'navbar_background' => '#5E1F13',
        'navbar_text' => '#FFFFFF',
        'hero_media_path' => null,
        'hero_media_type' => 'video',
    ];
}

function get_site_customization(mysqli $conn): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $settings = site_customization_defaults();
    $result = $conn->query('SELECT brand_name, brand_tagline, logo_path, login_image_path, register_image_path, admin_register_image_path, loading_style, primary_color, secondary_color, accent_color, page_background, surface_color, text_color, navbar_background, navbar_text, hero_media_path, hero_media_type FROM site_customization WHERE id = 1');
    $saved = $result->fetch_assoc();
    $result->free();
    if ($saved) {
        $settings = array_merge($settings, $saved);
    }

    if ($settings['logo_path'] !== 'images/logos.png'
        && (!is_string($settings['logo_path']) || !preg_match('#^images/site-customization/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$#', $settings['logo_path']))) {
        $settings['logo_path'] = 'images/logos.png';
    }
    foreach ([
        'login_image_path' => 'images/logback.jpeg',
        'register_image_path' => 'images/logback.jpeg',
        'admin_register_image_path' => 'images/logback.jpeg',
    ] as $image_key => $default_image) {
        if ($settings[$image_key] !== $default_image
            && (!is_string($settings[$image_key]) || !preg_match('#^site-customization/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$#', $settings[$image_key]))) {
            $settings[$image_key] = $default_image;
        }
    }

    foreach (['primary_color', 'secondary_color', 'accent_color', 'page_background', 'surface_color', 'text_color', 'navbar_background', 'navbar_text'] as $color_key) {
        if (!is_string($settings[$color_key]) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $settings[$color_key])) {
            $settings[$color_key] = site_customization_defaults()[$color_key];
        }
    }

    if (!in_array($settings['hero_media_type'], ['video', 'image'], true)) {
        $settings['hero_media_type'] = 'video';
    }
    if (!in_array($settings['loading_style'], ['rings', 'spinner', 'dots', 'progress', 'coffee', 'none'], true)) {
        $settings['loading_style'] = 'rings';
    }

    return $settings;
}

function site_customization_css(array $settings): string
{
    $primary = escapeOutput($settings['primary_color']);
    $secondary = escapeOutput($settings['secondary_color']);
    $accent = escapeOutput($settings['accent_color']);
    $page = escapeOutput($settings['page_background']);
    $surface = escapeOutput($settings['surface_color']);
    $text = escapeOutput($settings['text_color']);
    $navbar = escapeOutput($settings['navbar_background']);
    $navbarText = escapeOutput($settings['navbar_text']);

    return "<style id=\"site-customization-theme\">
:root{--primary-red:{$primary}!important;--dark-brown:{$secondary}!important;--gray-brown:{$accent}!important;--light-cream:{$page}!important;--white:{$surface}!important;--site-page-bg:{$page}!important;--site-surface:{$surface}!important;--site-text:{$text}!important;--site-navbar-bg:{$navbar}!important;--site-navbar-text:{$navbarText}!important}
html,body{background:var(--site-page-bg)!important;color:var(--site-text)}
.header-wrap,.header-wrap.scrolled,.admin-header{background:var(--site-navbar-bg)!important;color:var(--site-navbar-text)}
.header-wrap .brand,.header-wrap .brand .logo-main,.header-wrap .brand .logo-sub,.header-wrap .nav a,.header-wrap .icon-btn,.header-wrap .profile-btn,.admin-header .brand .logo-main,.admin-header .brand .logo-sub,.admin-header .nav-link,.admin-header .nav-dropdown-toggle,.admin-header .icon-btn{color:var(--site-navbar-text)}
.header-wrap .profile-menu,.admin-header .profile-menu,.admin-header .nav-dropdown-menu{background:var(--site-surface)!important;color:var(--site-text)}
.header-wrap .profile-menu a,.admin-header .profile-menu a,.admin-header .nav-dropdown-menu a{color:var(--site-text)}
.form-panel{background:var(--site-surface)!important}
.logo-text h3,.form-header .title,.form-panel label{color:var(--site-text)}
.home-btn,.guide-btn,.form-panel .btn,.form-panel button[type=submit]{background:var(--primary-red)}
</style>";
}

function site_customization_media_url(?string $path): ?string
{
    if (!is_string($path) || !preg_match('#^site-customization/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp|mp4|webm)$#', $path)) {
        return null;
    }
    return 'images/' . $path;
}

function save_site_customization(mysqli $conn, array $settings, int $adminId): void
{
    $stmt = $conn->prepare(
        'INSERT INTO site_customization
            (id, brand_name, brand_tagline, logo_path, login_image_path, register_image_path, admin_register_image_path, loading_style, primary_color, secondary_color, accent_color, page_background, surface_color, text_color, navbar_background, navbar_text, hero_media_path, hero_media_type, updated_by)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            brand_name = VALUES(brand_name), brand_tagline = VALUES(brand_tagline), logo_path = VALUES(logo_path),
            login_image_path = VALUES(login_image_path), register_image_path = VALUES(register_image_path),
            admin_register_image_path = VALUES(admin_register_image_path), loading_style = VALUES(loading_style),
            primary_color = VALUES(primary_color), secondary_color = VALUES(secondary_color), accent_color = VALUES(accent_color),
            page_background = VALUES(page_background), surface_color = VALUES(surface_color), text_color = VALUES(text_color),
            navbar_background = VALUES(navbar_background), navbar_text = VALUES(navbar_text),
            hero_media_path = VALUES(hero_media_path), hero_media_type = VALUES(hero_media_type), updated_by = VALUES(updated_by)'
    );
    $stmt->bind_param(
        'sssssssssssssssssi',
        $settings['brand_name'],
        $settings['brand_tagline'],
        $settings['logo_path'],
        $settings['login_image_path'],
        $settings['register_image_path'],
        $settings['admin_register_image_path'],
        $settings['loading_style'],
        $settings['primary_color'],
        $settings['secondary_color'],
        $settings['accent_color'],
        $settings['page_background'],
        $settings['surface_color'],
        $settings['text_color'],
        $settings['navbar_background'],
        $settings['navbar_text'],
        $settings['hero_media_path'],
        $settings['hero_media_type'],
        $adminId
    );
    $stmt->execute();
    $stmt->close();
}

function save_site_customization_upload(array $file, string $kind, string $projectRoot): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('The selected upload could not be received. Please try again.');
    }

    $maxBytes = $kind === 'hero' ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
    if (!isset($file['size']) || (int)$file['size'] < 1 || (int)$file['size'] > $maxBytes) {
        throw new RuntimeException($kind === 'hero' ? 'Homepage media must be no larger than 50 MB.' : 'The logo must be no larger than 5 MB.');
    }

    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = $kind !== 'hero'
        ? ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp']
        : ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm'];
    if (!isset($allowed[$extension]) || $mime !== $allowed[$extension]) {
        throw new RuntimeException($kind === 'hero'
            ? 'Use a valid JPG, PNG, WebP, MP4, or WebM homepage media file.'
            : ($kind === 'logo' ? 'Use a valid JPG, PNG, or WebP logo image.' : 'Use a valid JPG, PNG, or WebP picture.'));
    }
    if ($kind === 'hero' && str_starts_with($mime, 'video/') && !in_array($extension, ['mp4', 'webm'], true)) {
        throw new RuntimeException('Homepage video must be an MP4 or WebM file.');
    }

    $directory = $projectRoot . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'site_customization';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The customization upload directory could not be created.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('The uploaded file could not be saved. Check the server upload directory permissions.');
    }
    return 'site-customization/' . $filename;
}

function delete_site_customization_upload(?string $path, string $projectRoot): void
{
    if (site_customization_media_url($path) !== null || (is_string($path) && preg_match('#^site-customization/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$#', $path))) {
        $file = $projectRoot . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($file) && !unlink($file)) {
            error_log('Could not remove replaced site customization upload: ' . basename($file));
        }
    }
}

function site_customization_auth_image_url(?string $path, string $default): string
{
    if ($path === $default) {
        return $default;
    }
    return is_string($path) && preg_match('#^site-customization/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$#', $path)
        ? 'images/' . $path
        : $default;
}

function site_customization_loading_css(array $settings): string
{
    return '<style id="site-customization-loading">
    body[data-loading-style="spinner"] .loader-ring.second,body[data-loading-style="spinner"] .loader-dots,body[data-loading-style="spinner"] .loader-bar{display:none!important}
    body[data-loading-style="spinner"] .logout-loader-dots,body[data-loading-style="spinner"] .logout-loader-bar{display:none!important}
    body[data-loading-style="dots"] .loader-ring,body[data-loading-style="dots"] .loader-bar{display:none!important}
    body[data-loading-style="dots"] .logout-loader-spinner,body[data-loading-style="dots"] .logout-loader-bar{display:none!important}
    body[data-loading-style="progress"] .loader-ring,body[data-loading-style="progress"] .loader-dots{display:none!important}
    body[data-loading-style="progress"] .logout-loader-spinner,body[data-loading-style="progress"] .logout-loader-dots{display:none!important}
    body[data-loading-style="coffee"] .loader-ring,body[data-loading-style="coffee"] .loader-dots,body[data-loading-style="coffee"] .loader-bar{display:none!important}
    body[data-loading-style="coffee"] .coffee-loader{display:block!important}
    body[data-loading-style="coffee"] .logout-loader-spinner,body[data-loading-style="coffee"] .logout-loader-dots,body[data-loading-style="coffee"] .logout-loader-bar{display:none!important}
    body[data-loading-style]:not([data-loading-style="coffee"]) .coffee-loader{display:none!important}
    body[data-loading-style="none"] .loading-screen,body[data-loading-style="none"] #loader-overlay{display:none!important}
    .logout-loader-spinner{width:68px;height:68px;border:5px solid var(--light-cream);border-top-color:var(--primary-red);border-radius:50%;animation:siteLoaderSpin .9s linear infinite}
    .logout-loader-dots,.logout-loader-bar{display:none}
    .logout-loader-dots{align-items:center;gap:8px}
    .logout-loader-dots i{width:9px;height:9px;border-radius:50%;background:var(--primary-red);animation:siteLoaderBounce .8s infinite alternate}
    .logout-loader-dots i:nth-child(2){animation-delay:.15s}.logout-loader-dots i:nth-child(3){animation-delay:.3s}
    .logout-loader-bar{width:180px;height:6px;border-radius:8px;background:color-mix(in srgb,var(--primary-red) 18%,transparent);overflow:hidden}
    .logout-loader-bar i{display:block;width:40%;height:100%;border-radius:8px;background:var(--primary-red);animation:siteLoaderProgress 1.2s ease-in-out infinite}
    .coffee-loader{display:none!important;align-items:center;justify-content:center;color:var(--primary-red);font-size:2rem}
    .loading-screen .coffee-loader{font-size:3rem;animation:siteLoaderSpin 2.4s linear infinite}
    @keyframes siteLoaderSpin{to{transform:rotate(360deg)}}@keyframes siteLoaderBounce{to{transform:translateY(-7px);opacity:.55}}@keyframes siteLoaderProgress{from{transform:translateX(-110%)}to{transform:translateX(300%)}}
    body[data-loading-style="dots"] .logout-loader-dots,body[data-loading-style="progress"] .logout-loader-bar{display:flex}
    body[data-loading-style="progress"] .logout-loader-bar{display:block}
    .privacy-header{background:var(--site-navbar-bg)!important;color:var(--site-navbar-text)}
    .privacy-header .logo-section h1{color:var(--site-navbar-text)!important}
    .privacy-footer{background:var(--site-navbar-bg)!important;color:var(--site-navbar-text)}
    .privacy-footer p,.privacy-footer a{color:var(--site-navbar-text)!important}
    .privacy-policy .back-btn{background:var(--primary-red)}
    </style>';
}
