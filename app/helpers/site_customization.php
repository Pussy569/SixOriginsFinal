<?php

function site_customization_defaults(): array
{
    return [
        'brand_name' => 'Six Origins',
        'brand_tagline' => 'Cafe',
        'logo_path' => 'images/logos.png',
        'about_hero_image_path' => 'images/aboback.png',
        'about_cafe_image_path' => 'images/promo-cafe.png',
        'about_product_image_path' => 'images/promo-product.png',
        'team_members' => site_customization_default_team_members(),
        'login_image_path' => 'images/logback.jpeg',
        'register_image_path' => 'images/logback.jpeg',
        'admin_register_image_path' => 'images/logback.jpeg',
        'loading_style' => 'rings',
        'showcase_image_path' => 'images/coffee-splash.png',
        'showcase_background' => '#5E1F13',
        'showcase_heading_color' => '#FFFFFF',
        'showcase_text_color' => '#F2E9E4',
        'chatbot_image_path' => 'images/logos.png',
        'chatbot_primary_color' => '#C6453E',
        'chatbot_panel_color' => '#FFFBF7',
        'chatbot_text_color' => '#5E1F13',
        'chatbot_header_text_color' => '#FFFFFF',
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

function site_customization_default_team_members(): array
{
    return [
        ['name' => 'Dag-ay, Victor', 'image' => 'images/vic.jpg', 'role' => 'Founder & Visionary', 'bio' => 'Started Six Origins with one goal: coffee worth gathering around.'],
        ['name' => 'Camiller, Antonette', 'image' => 'images/ton.jpg', 'role' => 'Operations Lead', 'bio' => 'Keeps every shift, order, and detail running smoothly.'],
        ['name' => 'Velasco, Mark Jazper', 'image' => 'images/mjj.jpg', 'role' => 'Head Barista', 'bio' => 'Pulls every shot with the same care as the first.'],
        ['name' => 'Zafran, Michaella', 'image' => 'images/miks.jpg', 'role' => 'Creative Director', 'bio' => 'Shapes how Six Origins looks, feels, and sounds.'],
        ['name' => 'Biscocho, Dan Samuel', 'image' => 'images/dan.jpg', 'role' => 'Supply Manager', 'bio' => 'Sources every bean, keeping quality and freshness consistent.'],
        ['name' => 'Jacinto, Emerson', 'image' => 'images/eme.jpg', 'role' => 'Customer Care', 'bio' => 'The friendly face making sure every visit feels right.'],
    ];
}

function get_site_customization(mysqli $conn): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $settings = site_customization_defaults();
    $result = $conn->query('SELECT brand_name, brand_tagline, logo_path, about_hero_image_path, about_cafe_image_path, about_product_image_path, team_members, login_image_path, register_image_path, admin_register_image_path, loading_style, showcase_image_path, showcase_background, showcase_heading_color, showcase_text_color, chatbot_image_path, chatbot_primary_color, chatbot_panel_color, chatbot_text_color, chatbot_header_text_color, primary_color, secondary_color, accent_color, page_background, surface_color, text_color, navbar_background, navbar_text, hero_media_path, hero_media_type FROM site_customization WHERE id = 1');
    $saved = $result->fetch_assoc();
    $result->free();
    if ($saved) {
        $settings = array_merge($settings, $saved);
    }

    if ($settings['logo_path'] !== 'images/logos.png'
        && (!is_string($settings['logo_path']) || !preg_match('#^images/site-customization/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$#', $settings['logo_path']))) {
        $settings['logo_path'] = 'images/logos.png';
    }
    if ($settings['about_hero_image_path'] !== 'images/aboback.png'
        && (!is_string($settings['about_hero_image_path']) || !preg_match('#^site-customization/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$#', $settings['about_hero_image_path']))) {
        $settings['about_hero_image_path'] = 'images/aboback.png';
    }
    foreach (['about_cafe_image_path' => 'images/promo-cafe.png', 'about_product_image_path' => 'images/promo-product.png'] as $imageKey => $defaultImage) {
        if ($settings[$imageKey] !== $defaultImage
            && (!is_string($settings[$imageKey]) || !preg_match('#^site-customization/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$#', $settings[$imageKey]))) {
            $settings[$imageKey] = $defaultImage;
        }
    }
    $teamMembers = is_string($settings['team_members']) ? json_decode($settings['team_members'], true) : $settings['team_members'];
    $defaultTeamMembers = site_customization_default_team_members();
    if (!is_array($teamMembers) || count($teamMembers) !== count($defaultTeamMembers)) {
        $teamMembers = $defaultTeamMembers;
    } else {
        foreach ($defaultTeamMembers as $index => $defaultMember) {
            $member = $teamMembers[$index] ?? [];
            foreach (['name' => 100, 'role' => 60, 'bio' => 250] as $field => $maxLength) {
                if (!isset($member[$field]) || !is_string($member[$field]) || trim($member[$field]) === '' || strlen($member[$field]) > $maxLength) {
                    $member[$field] = $defaultMember[$field];
                }
            }
            $imagePath = $member['image'] ?? '';
            if (!is_string($imagePath)
                || (!preg_match('#^site-customization/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$#', $imagePath)
                    && !preg_match('#^images/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp)$#', $imagePath))) {
                $imagePath = $defaultMember['image'];
            }
            $teamMembers[$index] = [
                'name' => $member['name'],
                'role' => $member['role'],
                'bio' => $member['bio'],
                'image' => $imagePath,
            ];
        }
    }
    $settings['team_members'] = $teamMembers;
    foreach ([
        'login_image_path' => 'images/logback.jpeg',
        'register_image_path' => 'images/logback.jpeg',
        'admin_register_image_path' => 'images/logback.jpeg',
        'showcase_image_path' => 'images/coffee-splash.png',
        'chatbot_image_path' => 'images/logos.png',
    ] as $image_key => $default_image) {
        if ($settings[$image_key] !== $default_image
            && (!is_string($settings[$image_key]) || !preg_match('#^site-customization/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$#', $settings[$image_key]))) {
            $settings[$image_key] = $default_image;
        }
    }

    foreach (['primary_color', 'secondary_color', 'accent_color', 'page_background', 'surface_color', 'text_color', 'navbar_background', 'navbar_text', 'showcase_background', 'showcase_heading_color', 'showcase_text_color', 'chatbot_primary_color', 'chatbot_panel_color', 'chatbot_text_color', 'chatbot_header_text_color'] as $color_key) {
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
:root{--primary-red:{$primary}!important;--dark-brown:{$secondary}!important;--gray-brown:{$accent}!important;--light-cream:{$page}!important;--white:{$surface}!important;--red:{$primary}!important;--red-dark:{$secondary}!important;--brown:{$secondary}!important;--brown-mid:{$accent}!important;--cream:{$page}!important;--cream-light:{$page}!important;--cream-mid:{$surface}!important;--border:color-mix(in srgb,{$accent} 30%,{$page})!important;--site-page-bg:{$page}!important;--site-surface:{$surface}!important;--site-text:{$text}!important;--site-navbar-bg:{$navbar}!important;--site-navbar-text:{$navbarText}!important;--site-accent-color:{$accent}!important}
html,body{background:var(--site-page-bg)!important;color:var(--site-text)}
.header-wrap,.header-wrap.scrolled,.admin-header{background:var(--site-navbar-bg)!important;color:var(--site-navbar-text)}
.header-wrap .brand,.header-wrap .brand .logo-main,.header-wrap .brand .logo-sub,.header-wrap .nav a,.header-wrap .icon-btn,.header-wrap .profile-btn,.admin-header .brand .logo-main,.admin-header .brand .logo-sub,.admin-header .nav-link,.admin-header .nav-dropdown-toggle,.admin-header .icon-btn{color:var(--site-navbar-text)}
.header-wrap .profile-menu,.admin-header .profile-menu,.admin-header .nav-dropdown-menu{background:var(--site-surface)!important;color:var(--site-text)}
.header-wrap .profile-menu a,.admin-header .profile-menu a,.admin-header .nav-dropdown-menu a{color:var(--site-text)}
.form-panel{background:var(--site-surface)!important}
.logo-text h3,.form-header .title,.form-panel label{color:var(--site-text)}
.home-btn,.guide-btn,.form-panel .btn,.form-panel button[type=submit]{background:var(--primary-red)}
.form-panel .btn:not(.secondary),.modal-actions .btn:not(.secondary),.weekly-special-admin .btn:not(.secondary){background:linear-gradient(135deg,{$primary},{$secondary})!important;color:{$navbarText}!important;border-color:{$primary}!important}
.form-panel .btn.secondary,.modal-actions .btn.secondary,.weekly-special-admin .btn.secondary{background:{$page}!important;color:{$secondary}!important;border-color:color-mix(in srgb,{$accent} 30%,{$page})!important}
.form-panel input[type=file]::file-selector-button{background:linear-gradient(135deg,{$primary},{$secondary})!important;color:{$navbarText}!important;border:0!important;border-radius:8px;padding:8px 14px;font:inherit;font-weight:700;cursor:pointer}
.form-panel input[type=file]::file-selector-button:hover{filter:brightness(.94)}
.weekly-special-admin{background:{$page}!important;border-color:color-mix(in srgb,{$accent} 28%,{$page})!important}
.weekly-special-admin label{color:{$secondary}!important}
.weekly-special-admin textarea{background:{$surface}!important;color:{$text}!important;border-color:color-mix(in srgb,{$accent} 30%,{$surface})!important}
.page .btn-primary{background:linear-gradient(135deg,{$primary},{$secondary})!important;color:{$navbarText}!important;border-color:{$primary}!important}
.page .btn-primary:hover{background:{$secondary}!important;color:{$navbarText}!important}
.page .btn-ghost{background:{$page}!important;color:{$secondary}!important;border-color:color-mix(in srgb,{$accent} 28%,{$page})!important}
.page .btn-ghost:hover{background:{$primary}!important;color:{$navbarText}!important;border-color:{$primary}!important}
.page .table-wrapper thead th{background:linear-gradient(135deg,{$primary},{$secondary})!important;color:{$navbarText}!important}
.page .table-wrapper tbody td{border-color:color-mix(in srgb,{$accent} 18%,{$page})!important;color:{$text}}
.page .btn.refresh,.page .btn.approve,.page .btn.view{background:linear-gradient(135deg,{$primary},{$secondary})!important;color:{$navbarText}!important;border-color:{$primary}!important}
.page .btn.reject{background:{$page}!important;color:{$secondary}!important;border-color:color-mix(in srgb,{$accent} 30%,{$page})!important}
.page .btn.refresh:hover,.page .btn.approve:hover,.page .btn.view:hover{background:{$secondary}!important;color:{$navbarText}!important}
.dashboard-header{background:linear-gradient(135deg,{$primary} 0%,{$secondary} 100%)!important;color:{$navbarText}!important;box-shadow:0 14px 34px color-mix(in srgb,{$primary} 28%,transparent)!important}
.dashboard-header .title,.dashboard-header .title i,.dashboard-header .sub,.dashboard-header .sub>i{color:{$navbarText}!important}
.dashboard-header .action-btn:not(.secondary){background:{$surface}!important;color:{$secondary}!important}
.dashboard-header .action-btn.secondary{color:{$navbarText}!important}
.dashboard-header .action-btn:hover{background:{$surface}!important;color:{$secondary}!important}
.account-security-card{background:{$surface}!important;color:{$secondary}!important}
.account-security-card h2{color:{$secondary}!important}
.account-security-card p{color:{$accent}!important}
.account-security-code{background:{$page}!important;color:{$text}!important;border-color:{$accent}!important}
.account-security-reject{background:{$page}!important;color:{$secondary}!important}
.account-security-email{background:{$primary}!important;color:{$navbarText}!important}
.receipt-modal,#receipt-printable,.receipt-body{background:{$surface}!important;color:{$text}!important}
.receipt-header{background:linear-gradient(135deg,{$primary} 0%,{$secondary} 100%)!important}
.receipt-header::after{background:{$surface}!important}
.receipt-brand-logo{background:{$surface}!important}
.receipt-cafe-name,.receipt-cafe-tagline,.receipt-success-badge,.receipt-modal-actions .btn-close-modal:hover{color:{$navbarText}!important}
.receipt-info-item,.receipt-totals{background:{$page}!important;border-color:color-mix(in srgb,{$accent} 25%,{$page})!important}
.payment-pill{background:{$page}!important;border-color:color-mix(in srgb,{$accent} 35%,{$page})!important;color:{$secondary}!important}
.receipt-info-item .info-value,.receipt-order-id .order-num,.receipt-item-row .item-name,.receipt-item-row .item-sub,.receipt-barcode-lines span{color:{$secondary}!important}
.receipt-total-row,.receipt-order-id .order-date,.receipt-info-item .info-label,.receipt-items-title,.receipt-item-row .item-meta,.receipt-item-row .item-meta2,.receipt-footer-note,.receipt-barcode-num{color:{$accent}!important}
.receipt-item-row .item-custom,.receipt-total-row.grand{color:{$primary}!important}
.receipt-modal-actions .btn-primary{background:{$primary}!important;color:{$navbarText}!important}
.receipt-table{background:{$surface}!important;color:{$text}!important}
.receipt-table thead{background:{$primary}!important;color:{$navbarText}!important}
.receipt-divider,.receipt-order-id,.receipt-barcode-area{border-color:color-mix(in srgb,{$accent} 35%,{$page})!important}
.receipt-modal-actions .btn-close-modal{background:{$page}!important;color:{$secondary}!important;border-color:color-mix(in srgb,{$accent} 25%,{$page})!important}
.controls,.order-card,.contact-grid>.card,.contact-grid .info-card,.contact-grid .thread-row,.contact-grid .thread-list,.contact-grid .reply-admin,.contact-grid .reply-user,.modal .modal-content,.modal .original-message,.modal .reply-admin,.modal .reply-user,.checkout-wrapper>.card,.profile-wrap>.card,.sx-card,.sx-hero,.member,.stat-card,.table-panel,.products-catalog-card,.products-section .product-card,.products-section .contact-cta{background:{$surface}!important;color:{$text}!important;border-color:color-mix(in srgb,{$accent} 22%,{$page})!important}
.order-card .card-header,.order-card .order-total-badge,.contact-grid .btn:not(.btn-ghost-small),.contact-grid .thread-row .btn,.sx-btn-primary,.profile-wrap .btn-primary,.checkout-wrapper .btn-primary{background:linear-gradient(135deg,{$primary},{$secondary})!important;color:{$navbarText}!important;border-color:{$primary}!important}
.order-card .card-body,.order-card .card-footer,.contact-grid .thread-row,.contact-grid .thread-list,.profile-wrap .profile-summary,.checkout-wrapper .checkout-summary{background:{$surface}!important;color:{$text}!important}
.order-card .card-body,.order-card .card-footer,.contact-grid .thread-row,.profile-wrap .profile-summary{border-color:color-mix(in srgb,{$accent} 20%,{$page})!important}
.controls input,.controls select,.contact-grid input,.contact-grid textarea,.contact-grid select,.checkout-wrapper input,.checkout-wrapper textarea,.checkout-wrapper select,.profile-wrap input,.profile-wrap textarea,.profile-wrap select,.sx-body input,.sx-body select,.products-section input,.products-section select,.page-wrapper .field input,.page-wrapper .field select,.page-wrapper .search-box input,.page-wrapper .filter-select,.page-wrapper .modal-field input{background:{$surface}!important;color:{$text}!important;border-color:color-mix(in srgb,{$accent} 30%,{$page})!important}
.controls label,.order-card .order-title,.order-card .order-title-sub,.contact-grid h2,.contact-grid h3,.contact-grid h4,.checkout-wrapper h2,.checkout-wrapper h3,.profile-wrap h2,.profile-wrap h3,.sx-body h2,.sx-body h3,.sx-body .sx-price{color:{$secondary}!important}
.summary-pill{background:linear-gradient(135deg,{$primary},{$secondary})!important;color:{$navbarText}!important;border-color:{$primary}!important}
.checkout-wrapper .discount-section,.profile-wrap .profile-actions,.sx-body .sx-tab{background:{$page}!important;color:{$secondary}!important;border-color:color-mix(in srgb,{$accent} 25%,{$page})!important}
.sx-body .sx-tab.active{background:linear-gradient(135deg,{$primary},{$secondary})!important;color:{$navbarText}!important;border-color:{$primary}!important}
.sx-body .sx-btn-outline,.profile-wrap .btn-ghost,.checkout-wrapper .btn-ghost,.contact-grid .btn-ghost-small,.products-section .btn-outline{background:{$page}!important;color:{$secondary}!important;border-color:color-mix(in srgb,{$accent} 28%,{$page})!important}
.products-section .btn-primary{background:linear-gradient(135deg,{$primary},{$secondary})!important;color:{$navbarText}!important;border-color:{$primary}!important}
.contact-grid .thread-row-header,.contact-grid .reply-header,.contact-grid .thread-name,.contact-grid .inbox-header-title,.contact-grid .original-message-title{color:{$secondary}!important}
.contact-grid .reply-text,.contact-grid .thread-preview,.contact-grid .original-message,.contact-grid .reply-admin,.contact-grid .reply-user,.modal .modal-content,.modal .original-message,.modal .reply-admin,.modal .reply-user{color:{$text}!important;border-color:color-mix(in srgb,{$accent} 22%,{$page})!important}
.team .member,.sx-card .sx-body-card,.checkout-wrapper .card,.profile-wrap .card,.contact-grid>.card{box-shadow:0 8px 24px color-mix(in srgb,{$secondary} 8%,transparent)!important}
.stat-card h3,.stat-card .stat-value,.table-panel .toolbar h2,.page-header-left h1{color:{$secondary}!important}
.form-panel-header,.btn-submit:not(:disabled),.btn-modal-save:not(:disabled),.modal-header{background:linear-gradient(135deg,{$primary},{$secondary})!important;color:{$navbarText}!important}
.table-panel thead th{background:{$page}!important;color:{$secondary}!important}
.page-wrapper .form-panel,.page-wrapper .table-panel,.page-wrapper .stat-card{border-color:color-mix(in srgb,{$accent} 22%,{$page})!important}
.page-wrapper .field label,.page-wrapper .modal-field label,.page-wrapper .toolbar h2{color:{$secondary}!important}
.page-wrapper .btn-edit-icon{background:{$page}!important;color:{$primary}!important}
.site-footer{background:linear-gradient(135deg,{$navbar},{$secondary})!important}
.team .member .role,.team .member .social-btn{background:{$page}!important;color:{$primary}!important}
.team .member h4{color:{$secondary}!important}
.promo,.promo--light .promo-body,.quick-facts,.facts-card,.contact-cta{background:{$surface}!important;color:{$text}!important;border-color:color-mix(in srgb,{$accent} 22%,{$page})!important}
.hero .btn,.promo-btn,.contact-cta .btn,.quick-facts .btn,.thread-actions a,.cancel-customer-btn:not(:disabled){background:linear-gradient(135deg,{$primary},{$secondary})!important;color:{$navbarText}!important;border-color:{$primary}!important}
.order-card .card-header{background:linear-gradient(135deg,{$primary},{$secondary})!important;color:{$navbarText}!important}
.receipt-table tbody td,.order-card .card-body,.contact-grid p,.profile-wrap p,.checkout-wrapper p,.sx-body .sx-description{color:{$text}}
</style>";
}

function site_customization_media_url(?string $path): ?string
{
    if (!is_string($path) || !preg_match('#^site-customization/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp|mp4|webm)$#', $path)) {
        return null;
    }
    return 'images/site_customization/' . substr($path, strlen('site-customization/'));
}

function site_customization_video_url(?string $path): ?string
{
    if (!is_string($path) || !preg_match('#^site-customization/[a-f0-9]{32}\.(?:mp4|webm)$#', $path)) {
        return null;
    }

    return site_customization_media_url($path);
}

function site_customization_logo_url(?string $path): string
{
    if ($path === 'images/logos.png') {
        return $path;
    }

    if (!is_string($path) || !preg_match('#^images/site-customization/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$#', $path)) {
        return 'images/logos.png';
    }

    return 'images/site_customization/' . substr($path, strlen('images/site-customization/'));
}

function save_site_customization(mysqli $conn, array $settings, int $adminId): void
{
    $teamMembers = json_encode($settings['team_members'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($teamMembers === false) {
        throw new RuntimeException('Team information could not be encoded for saving.');
    }
    $stmt = $conn->prepare(
        'INSERT INTO site_customization
            (id, brand_name, brand_tagline, logo_path, about_hero_image_path, about_cafe_image_path, about_product_image_path, team_members, login_image_path, register_image_path, admin_register_image_path, loading_style, showcase_image_path, showcase_background, showcase_heading_color, showcase_text_color, chatbot_image_path, chatbot_primary_color, chatbot_panel_color, chatbot_text_color, chatbot_header_text_color, primary_color, secondary_color, accent_color, page_background, surface_color, text_color, navbar_background, navbar_text, hero_media_path, hero_media_type, updated_by)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            brand_name = VALUES(brand_name), brand_tagline = VALUES(brand_tagline), logo_path = VALUES(logo_path),
            about_hero_image_path = VALUES(about_hero_image_path), about_cafe_image_path = VALUES(about_cafe_image_path),
            about_product_image_path = VALUES(about_product_image_path), team_members = VALUES(team_members),
            login_image_path = VALUES(login_image_path), register_image_path = VALUES(register_image_path),
            admin_register_image_path = VALUES(admin_register_image_path), loading_style = VALUES(loading_style),
            showcase_image_path = VALUES(showcase_image_path), showcase_background = VALUES(showcase_background),
            showcase_heading_color = VALUES(showcase_heading_color), showcase_text_color = VALUES(showcase_text_color),
            chatbot_image_path = VALUES(chatbot_image_path), chatbot_primary_color = VALUES(chatbot_primary_color),
            chatbot_panel_color = VALUES(chatbot_panel_color), chatbot_text_color = VALUES(chatbot_text_color),
            chatbot_header_text_color = VALUES(chatbot_header_text_color),
            primary_color = VALUES(primary_color), secondary_color = VALUES(secondary_color), accent_color = VALUES(accent_color),
            page_background = VALUES(page_background), surface_color = VALUES(surface_color), text_color = VALUES(text_color),
            navbar_background = VALUES(navbar_background), navbar_text = VALUES(navbar_text),
            hero_media_path = VALUES(hero_media_path), hero_media_type = VALUES(hero_media_type), updated_by = VALUES(updated_by)'
    );
    $stmt->bind_param(
        str_repeat('s', 30) . 'i',
        $settings['brand_name'],
        $settings['brand_tagline'],
        $settings['logo_path'],
        $settings['about_hero_image_path'],
        $settings['about_cafe_image_path'],
        $settings['about_product_image_path'],
        $teamMembers,
        $settings['login_image_path'],
        $settings['register_image_path'],
        $settings['admin_register_image_path'],
        $settings['loading_style'],
        $settings['showcase_image_path'],
        $settings['showcase_background'],
        $settings['showcase_heading_color'],
        $settings['showcase_text_color'],
        $settings['chatbot_image_path'],
        $settings['chatbot_primary_color'],
        $settings['chatbot_panel_color'],
        $settings['chatbot_text_color'],
        $settings['chatbot_header_text_color'],
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
        throw new RuntimeException($kind === 'hero' ? 'Homepage video must be no larger than 50 MB.' : 'The selected image must be no larger than 5 MB.');
    }

    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = $kind !== 'hero'
        ? ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp']
        : ['mp4' => 'video/mp4', 'webm' => 'video/webm'];
    if (!isset($allowed[$extension]) || $mime !== $allowed[$extension]) {
        throw new RuntimeException($kind === 'hero'
            ? 'Use a valid MP4 or WebM video for the homepage background.'
            : ($kind === 'logo' ? 'Use a valid JPG, PNG, or WebP logo image.' : 'Use a valid JPG, PNG, or WebP picture.'));
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
        ? 'images/site_customization/' . substr($path, strlen('site-customization/'))
        : $default;
}

function site_customization_chatbot_css(array $settings): string
{
    $primary = escapeOutput($settings['chatbot_primary_color']);
    $panel = escapeOutput($settings['chatbot_panel_color']);
    $text = escapeOutput($settings['chatbot_text_color']);
    $headerText = escapeOutput($settings['chatbot_header_text_color']);
    return "<style id=\"site-customization-chatbot\">
#chatbot-root{--cb-red:{$primary};--cb-red-deep:{$primary};--cb-brown:{$text};--cb-gray:{$text};--cb-cream:{$panel};--cb-line:color-mix(in srgb,{$text} 16%,{$panel})}
#chatbot-root #chatbot-box,#chatbot-root #cb-body,#chatbot-root .cb-quick-bar,#chatbot-root .cb-quick-btn:not(.hot){background:{$panel}}
#chatbot-root #cb-messages{background:{$panel}}
#chatbot-root .cb-msg-bubble{background:{$panel}!important;color:{$text}!important}
#chatbot-root .cb-header,#chatbot-root #chatbot-launcher,#chatbot-root .cb-quick-btn.hot,#chatbot-root .cb-msg-row.user .cb-msg-bubble,#chatbot-root .cb-msg-row.user .cb-avatar{background:{$primary};color:{$headerText}}
#chatbot-root #chatbot-launcher{background:linear-gradient(135deg,{$primary},{$primary})!important;color:{$headerText}!important}
#chatbot-root #chatbot-launcher:hover{background:linear-gradient(135deg,{$primary},{$text})!important;color:{$headerText}!important}
#chatbot-root .cb-name,#chatbot-root .cb-status,#chatbot-root .cb-icon-btn,#chatbot-root .cb-quick-btn.hot,#chatbot-root .cb-msg-row.user .cb-msg-bubble{color:{$headerText}}
#chatbot-root .cb-msg-bubble,#chatbot-root .cb-quick-btn:not(.hot){color:{$text}}
#chatbot-root .cb-msg-bubble a,#chatbot-root .cb-quick-btn i{color:{$primary}}
.chatbot-fab,.chatbot-header{background:{$primary}!important;color:{$headerText}!important}
.chatbot-fab,.chatbot-fab.active{background:linear-gradient(135deg,{$primary},{$primary})!important;color:{$headerText}!important}
.chatbot-fab:hover{background:linear-gradient(135deg,{$primary},{$text})!important;color:{$headerText}!important}
.chatbot-window{background:{$panel}!important}
.chatbot-messages{background:{$panel}}
.chatbot-window .cb-message-content,.chatbot-window .bot-response-text,.chatbot-window .cb-quick-btn{color:{$text}}
.chatbot-window .cb-message.bot .cb-message-content,.chatbot-window .chatbot-input-area,.chatbot-window .chatbot-window-body{background:{$panel}!important}
.chatbot-window .cb-message.user .cb-message-content,.chatbot-window .cb-quick-btn:hover,.chatbot-window .chatbot-send{background:{$primary}!important;color:{$headerText}!important}
.chatbot-window .bot-response-title,.chatbot-window .bot-response-item-value,.chatbot-window .cb-typing-dot{color:{$primary}}
.chatbot-window .bot-response-data{border-left-color:{$primary};color:{$text}}
.chatbot-window .chatbot-input{color:{$text}}
.chatbot-window .chatbot-close{color:{$headerText}}
.chatbot-window .cb-quick-btn{border-color:{$primary}}
.chatbot-header-title .admin-chatbot-brand{width:34px;height:34px;object-fit:cover;border-radius:50%;background:#fff;padding:2px;flex:none}
.coffee-moment{background:var(--coffee-moment-background)!important}
.coffee-moment-text h2{color:var(--coffee-moment-heading)!important}
.coffee-moment-text p{color:var(--coffee-moment-copy)!important}
</style>";
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
