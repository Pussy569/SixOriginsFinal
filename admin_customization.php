<?php
session_start();
include 'config.php';
require_once __DIR__ . '/app/services/admin_log_activity.php';
require_once __DIR__ . '/app/helpers/site_customization.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}
if (!is_head_admin()) {
    http_response_code(403);
    exit('Only the Head Admin can customize the site.');
}

$settings = get_site_customization($conn);
$message = '';
$messageType = 'success';
$newUploads = [];
$saveCompleted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }

        $brandName = trim((string)($_POST['brand_name'] ?? ''));
        $brandTagline = trim((string)($_POST['brand_tagline'] ?? ''));
        if ($brandName === '' || strlen($brandName) > 60 || strlen($brandTagline) > 80) {
            throw new RuntimeException('Enter a brand name up to 60 characters and a tagline up to 80 characters.');
        }

        $newSettings = $settings;
        $newSettings['brand_name'] = $brandName;
        $newSettings['brand_tagline'] = $brandTagline;
        $postedContentTexts = $_POST['content_texts'] ?? [];
        if (!is_array($postedContentTexts)) {
            throw new RuntimeException('Enter valid text for the site content fields.');
        }
        foreach (site_customization_content_fields() as $contentFields) {
            foreach ($contentFields as $key => $field) {
                $value = $postedContentTexts[$key] ?? $settings['content_texts'][$key] ?? $field[1];
                if (!is_string($value) || strlen($value) > 1000) {
                    throw new RuntimeException('Each site text field must be no longer than 1,000 characters.');
                }
                $newSettings['content_texts'][$key] = trim($value);
            }
        }
        $loadingStyle = (string)($_POST['loading_style'] ?? '');
        if (!in_array($loadingStyle, ['rings', 'spinner', 'dots', 'progress', 'coffee', 'none'], true)) {
            throw new RuntimeException('Choose a valid loading animation.');
        }
        $newSettings['loading_style'] = $loadingStyle;
        foreach (['primary_color', 'secondary_color', 'accent_color', 'page_background', 'surface_color', 'text_color', 'navbar_background', 'navbar_text', 'navbar_hover_color', 'navbar_hover_text_color', 'navbar_hover_border_color', 'order_header_text_color'] as $key) {
            $value = (string)($_POST[$key] ?? '');
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                throw new RuntimeException('Choose a valid six-digit hex color for each color setting.');
            }
            $newSettings[$key] = strtoupper($value);
        }
        foreach ([
            'chatbot_primary_color',
            'chatbot_panel_color',
            'chatbot_text_color',
            'chatbot_header_text_color',
        ] as $key) {
            $value = (string)($_POST[$key] ?? '');
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                throw new RuntimeException('Choose a valid six-digit hex color for each color setting.');
            }
            $newSettings[$key] = strtoupper($value);
        }
        $newSettings['showcase_background'] = $newSettings['navbar_background'];
        $newSettings['showcase_heading_color'] = $newSettings['navbar_text'];
        $newSettings['showcase_text_color'] = $newSettings['navbar_text'];

        $oldLogo = $settings['logo_path'];
        $uploadedLogo = save_site_customization_upload($_FILES['logo'] ?? [], 'logo', __DIR__);
        if ($uploadedLogo !== null) {
            $newUploads[] = $uploadedLogo;
            $newSettings['logo_path'] = 'images/' . $uploadedLogo;
        } elseif (!empty($_POST['reset_logo'])) {
            $newSettings['logo_path'] = site_customization_defaults()['logo_path'];
        }

        $oldAuthImages = [];
        foreach ([
            'login_image_path' => 'login_image',
            'register_image_path' => 'register_image',
            'admin_register_image_path' => 'admin_register_image',
            'showcase_image_path' => 'showcase_image',
            'chatbot_image_path' => 'chatbot_image',
        ] as $settingKey => $uploadKey) {
            $oldAuthImages[$settingKey] = $settings[$settingKey];
            $uploadedImage = save_site_customization_upload($_FILES[$uploadKey] ?? [], 'image', __DIR__);
            if ($uploadedImage !== null) {
                $newUploads[] = $uploadedImage;
                $newSettings[$settingKey] = $uploadedImage;
            } elseif (!empty($_POST['reset_' . $uploadKey])) {
                $newSettings[$settingKey] = site_customization_defaults()[$settingKey];
            }
        }

        $oldAboutImages = [];
        foreach ([
            'about_hero_image_path' => 'about_hero_image',
            'about_cafe_image_path' => 'about_cafe_image',
            'about_product_image_path' => 'about_product_image',
            'about_impact_1_image_path' => 'about_impact_image_1',
            'about_impact_2_image_path' => 'about_impact_image_2',
            'about_impact_3_image_path' => 'about_impact_image_3',
            'about_why_image_path' => 'about_why_image',
            'home_about_slide_1_image_path' => 'home_about_slide_1_image',
            'home_about_slide_2_image_path' => 'home_about_slide_2_image',
            'home_about_slide_3_image_path' => 'home_about_slide_3_image',
            'home_about_slide_4_image_path' => 'home_about_slide_4_image',
        ] as $settingKey => $uploadKey) {
            $oldAboutImages[$settingKey] = $settings[$settingKey];
            $uploadedAboutImage = save_site_customization_upload($_FILES[$uploadKey] ?? [], 'image', __DIR__);
            if ($uploadedAboutImage !== null) {
                $newUploads[] = $uploadedAboutImage;
                $newSettings[$settingKey] = $uploadedAboutImage;
            } elseif (!empty($_POST['reset_' . $uploadKey])) {
                $newSettings[$settingKey] = site_customization_defaults()[$settingKey];
            }
        }

        $pageBackgrounds = [
            'shop_background_image_path' => 'shop_background',
            'contact_background_image_path' => 'contact_background',
            'orders_background_image_path' => 'orders_background',
            'search_background_image_path' => 'search_background',
            'cart_background_image_path' => 'cart_background',
            'profile_background_image_path' => 'profile_background',
            'topup_background_image_path' => 'topup_background',
        ];
        $oldPageBackgrounds = [];
        foreach ($pageBackgrounds as $settingKey => $uploadKey) {
            $oldPageBackgrounds[$settingKey] = $settings[$settingKey];
            $uploadedBackground = save_site_customization_upload($_FILES[$uploadKey] ?? [], 'image', __DIR__);
            if ($uploadedBackground !== null) {
                $newUploads[] = $uploadedBackground;
                $newSettings[$settingKey] = $uploadedBackground;
            } elseif (!empty($_POST['reset_' . $uploadKey])) {
                $newSettings[$settingKey] = site_customization_defaults()[$settingKey];
            }
        }

        $oldVerificationBackground = $settings['email_verification_background_image_path'];
        $uploadedVerificationBackground = save_site_customization_upload($_FILES['email_verification_background'] ?? [], 'image', __DIR__);
        if ($uploadedVerificationBackground !== null) {
            $newUploads[] = $uploadedVerificationBackground;
            $newSettings['email_verification_background_image_path'] = $uploadedVerificationBackground;
        } elseif (!empty($_POST['reset_email_verification_background'])) {
            $newSettings['email_verification_background_image_path'] = null;
        }

        $oldTeamMembers = $settings['team_members'];
        $postedNames = $_POST['team_name'] ?? [];
        $postedRoles = $_POST['team_role'] ?? [];
        $postedBios = $_POST['team_bio'] ?? [];
        $postedTeamPhotoResets = $_POST['reset_team_photo'] ?? [];
        if (!is_array($postedNames) || !is_array($postedRoles) || !is_array($postedBios) || !is_array($postedTeamPhotoResets)) {
            throw new RuntimeException('Enter valid text for each team member.');
        }
        if (count($postedNames) > 30) {
            throw new RuntimeException('You can add up to 30 team members.');
        }
        $newTeamMembers = [];
        foreach ($postedNames as $index => $nameValue) {
            $roleValue = $postedRoles[$index] ?? '';
            $bioValue = $postedBios[$index] ?? '';
            if (!is_string($nameValue) || !is_string($roleValue) || !is_string($bioValue)) {
                throw new RuntimeException('Enter valid text for each team member.');
            }
            $name = trim($nameValue);
            $role = trim($roleValue);
            $bio = trim($bioValue);
            if ($name === '' || strlen($name) > 100 || $role === '' || strlen($role) > 60 || $bio === '' || strlen($bio) > 250) {
                throw new RuntimeException('Each team member needs a name, role, and bio within the displayed character limits.');
            }

            $oldMember = $oldTeamMembers[$index] ?? null;
            $defaultMember = site_customization_default_team_members()[$index] ?? null;
            $teamImage = is_array($oldMember)
                ? $oldMember['image']
                : ($defaultMember['image'] ?? 'images/logos.png');
            $teamPhotoFile = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $fileKey) {
                $teamPhotoFile[$fileKey] = $_FILES['team_photo'][$fileKey][$index] ?? null;
            }
            $uploadedTeamPhoto = save_site_customization_upload($teamPhotoFile, 'image', __DIR__);
            if ($uploadedTeamPhoto !== null) {
                $newUploads[] = $uploadedTeamPhoto;
                $teamImage = $uploadedTeamPhoto;
            } elseif (!empty($postedTeamPhotoResets[$index])) {
                $teamImage = $defaultMember['image'] ?? 'images/logos.png';
            }
            $newTeamMembers[] = ['name' => $name, 'role' => $role, 'bio' => $bio, 'image' => $teamImage];
        }
        $newSettings['team_members'] = $newTeamMembers;

        $oldMedia = $settings['hero_media_path'];
        if (!empty($_POST['reset_hero_media'])) {
            $newSettings['hero_media_path'] = null;
            $newSettings['hero_media_type'] = 'video';
        } else {
            $uploadedMedia = save_site_customization_upload($_FILES['hero_media'] ?? [], 'hero', __DIR__);
            if ($uploadedMedia !== null) {
                $newUploads[] = $uploadedMedia;
                $newSettings['hero_media_path'] = $uploadedMedia;
                $newSettings['hero_media_type'] = 'video';
            } elseif ($settings['hero_media_type'] === 'image') {
                $newSettings['hero_media_path'] = null;
                $newSettings['hero_media_type'] = 'video';
            }
        }

        save_site_customization($conn, $newSettings, (int)$_SESSION['admin_id']);
        $saveCompleted = true;
        log_admin_activity(
            (int)$_SESSION['admin_id'],
            'Updated Site Customization',
            'Updated site branding, colors, page banners, homepage media, or About page content.',
            'success',
            'site_customization',
            1,
            null,
            ['brand_name' => $newSettings['brand_name'], 'hero_media_type' => $newSettings['hero_media_type'], 'loading_style' => $newSettings['loading_style']]
        );
        if ($oldLogo !== $newSettings['logo_path'] && str_starts_with((string)$oldLogo, 'images/site-customization/')) {
            delete_site_customization_upload(substr($oldLogo, strlen('images/')), __DIR__);
        }
        if ($oldMedia !== $newSettings['hero_media_path']) {
            delete_site_customization_upload($oldMedia, __DIR__);
        }
        foreach ($oldAboutImages as $settingKey => $oldAboutImage) {
            if ($oldAboutImage !== $newSettings[$settingKey]) {
                delete_site_customization_upload($oldAboutImage, __DIR__);
            }
        }
        foreach ($oldPageBackgrounds as $settingKey => $oldPageBackground) {
            if ($oldPageBackground !== $newSettings[$settingKey]) {
                delete_site_customization_upload($oldPageBackground, __DIR__);
            }
        }
        if ($oldVerificationBackground !== $newSettings['email_verification_background_image_path']) {
            delete_site_customization_upload($oldVerificationBackground, __DIR__);
        }
        foreach ($oldTeamMembers as $oldMember) {
            $imageIsRetained = false;
            foreach ($newTeamMembers as $newMember) {
                if ($oldMember['image'] === $newMember['image']) {
                    $imageIsRetained = true;
                    break;
                }
            }
            if (!$imageIsRetained) {
                delete_site_customization_upload($oldMember['image'], __DIR__);
            }
        }
        foreach ($oldAuthImages as $settingKey => $oldAuthImage) {
            if ($oldAuthImage !== $newSettings[$settingKey]) {
                delete_site_customization_upload($oldAuthImage, __DIR__);
            }
        }
        $settings = $newSettings;
        $message = 'Site customization saved successfully.';
    } catch (mysqli_sql_exception $e) {
        if (!$saveCompleted) {
            foreach ($newUploads as $newUpload) {
                delete_site_customization_upload($newUpload, __DIR__);
            }
        }
        error_log('Site customization database save failed: ' . $e->getMessage());
        $message = 'The customization could not be saved. Please try again.';
        $messageType = 'error';
    } catch (RuntimeException $e) {
        if (!$saveCompleted) {
            foreach ($newUploads as $newUpload) {
                delete_site_customization_upload($newUpload, __DIR__);
            }
        }
        error_log('Site customization save failed: ' . $e->getMessage());
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$logoUrl = (string)$settings['logo_path'];
if ($logoUrl !== 'images/logos.png') {
    $logoUrl = site_customization_media_url(substr($logoUrl, strlen('images/'))) ?? 'images/logos.png';
}
$mediaUrl = site_customization_video_url($settings['hero_media_path']);
$hasLegacyHeroImage = $settings['hero_media_type'] === 'image' && $settings['hero_media_path'] !== null;
$aboutCafeImageUrl = site_customization_auth_image_url($settings['about_cafe_image_path'], 'images/promo-cafe.png');
$aboutProductImageUrl = site_customization_auth_image_url($settings['about_product_image_path'], 'images/promo-product.png');
$aboutImpactImages = [];
foreach ([
    'about_impact_1_image_path' => ['title' => 'About card 1 image', 'input' => 'about_impact_image_1', 'default' => 'images/ge.jpg'],
    'about_impact_2_image_path' => ['title' => 'About card 2 image', 'input' => 'about_impact_image_2', 'default' => 'images/gee.jpg'],
    'about_impact_3_image_path' => ['title' => 'About card 3 image', 'input' => 'about_impact_image_3', 'default' => 'images/geee.jpg'],
    'about_why_image_path' => ['title' => 'About quick facts image', 'input' => 'about_why_image', 'default' => 'images/ey.jpg'],
] as $settingKey => $imageConfig) {
    $aboutImpactImages[$settingKey] = [
        'title' => $imageConfig['title'],
        'input' => $imageConfig['input'],
        'default' => $imageConfig['default'],
        'url' => site_customization_auth_image_url($settings[$settingKey], $imageConfig['default']),
    ];
}
$contentFieldGroups = site_customization_content_fields();
$contentFieldsByPage = [
    'home' => [],
    'about' => [],
    'shop' => [],
    'contact' => [],
    'orders' => [],
    'search' => [],
    'cart' => [],
    'profile' => [],
    'topup' => [],
    'footer' => [],
    'loading' => [],
    'account' => [],
    'verification' => [],
];
$contentPagePrefixes = [
    'loading' => ['login_loading_text', 'login_submit_loading_text'],
    'home' => ['home_'],
    'about' => ['about_'],
    'shop' => ['shop_'],
    'contact' => ['contact_'],
    'orders' => ['orders_'],
    'search' => ['search_'],
    'cart' => ['cart_'],
    'profile' => ['profile_'],
    'topup' => ['topup_'],
    'footer' => ['footer_'],
    'account' => ['login_', 'register_', 'logout_'],
    'verification' => ['verification_'],
];
foreach ($contentFieldGroups as $contentFields) {
    foreach ($contentFields as $key => $field) {
        foreach ($contentPagePrefixes as $pageKey => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $contentFieldsByPage[$pageKey][$key] = $field;
                    continue 3;
                }
            }
        }
    }
}
$homeCarouselImages = [];
foreach ([
    'home_about_slide_1_image_path' => ['title' => 'Coffee origins slide', 'input' => 'home_about_slide_1_image'],
    'home_about_slide_2_image_path' => ['title' => 'Expert roasting slide', 'input' => 'home_about_slide_2_image'],
    'home_about_slide_3_image_path' => ['title' => 'Artisan brewing slide', 'input' => 'home_about_slide_3_image'],
    'home_about_slide_4_image_path' => ['title' => 'Premium apparel slide', 'input' => 'home_about_slide_4_image'],
] as $settingKey => $imageConfig) {
    $homeCarouselImages[$settingKey] = [
        'title' => $imageConfig['title'],
        'input' => $imageConfig['input'],
        'url' => site_customization_auth_image_url($settings[$settingKey], 'images/typica bg.png'),
    ];
}
$pageBackgroundOptions = [
    'about_hero_image_path' => ['title' => 'About page hero/banner', 'input' => 'about_hero_image', 'default' => 'images/aboback.png'],
    'shop_background_image_path' => ['title' => 'Shop hero/banner', 'input' => 'shop_background'],
    'contact_background_image_path' => ['title' => 'Contact hero/banner', 'input' => 'contact_background'],
    'orders_background_image_path' => ['title' => 'Customer orders hero/banner', 'input' => 'orders_background'],
    'search_background_image_path' => ['title' => 'Search hero/banner', 'input' => 'search_background'],
    'cart_background_image_path' => ['title' => 'Shopping cart hero/banner', 'input' => 'cart_background'],
    'profile_background_image_path' => ['title' => 'Customer profile hero/banner', 'input' => 'profile_background'],
    'topup_background_image_path' => ['title' => 'Wallet top-up hero/banner', 'input' => 'topup_background'],
];
$pageBackgroundImages = [];
foreach ($pageBackgroundOptions as $settingKey => $imageConfig) {
    $pageBackgroundImages[$settingKey] = [
        'title' => $imageConfig['title'],
        'input' => $imageConfig['input'],
        'url' => site_customization_auth_image_url($settings[$settingKey], $imageConfig['default'] ?? ''),
    ];
}
$teamMembers = $settings['team_members'];
$authImages = [
    'login_image_path' => ['title' => 'Login page picture', 'input' => 'login_image', 'default' => 'images/logback.jpeg'],
    'register_image_path' => ['title' => 'Customer registration picture', 'input' => 'register_image', 'default' => 'images/logback.jpeg'],
    'admin_register_image_path' => ['title' => 'Admin registration picture', 'input' => 'admin_register_image', 'default' => 'images/logback.jpeg'],
];
$customizationPages = [
    'home' => ['id' => 'home-customization', 'title' => 'Homepage', 'icon' => 'fa-house', 'description' => 'Customize the homepage copy and images. The background video and loading animation are in the system setup above.', 'banner' => null],
    'about' => ['id' => 'about-customization', 'title' => 'About page', 'icon' => 'fa-circle-question', 'description' => 'Customize About page text, banner, story images, and team profiles.', 'banner' => 'about_hero_image_path'],
    'shop' => ['id' => 'shop-customization', 'title' => 'Shop', 'icon' => 'fa-bag-shopping', 'description' => 'Customize the shop banner and related text.', 'banner' => 'shop_background_image_path'],
    'contact' => ['id' => 'contact-customization', 'title' => 'Contact', 'icon' => 'fa-envelope', 'description' => 'Customize the contact banner, buttons, and contact information.', 'banner' => 'contact_background_image_path'],
    'orders' => ['id' => 'orders-customization', 'title' => 'Orders', 'icon' => 'fa-box', 'description' => 'Customize the customer orders banner and its text.', 'banner' => 'orders_background_image_path'],
    'search' => ['id' => 'search-customization', 'title' => 'Search', 'icon' => 'fa-magnifying-glass', 'description' => 'Customize the search page banner and its text.', 'banner' => 'search_background_image_path'],
    'cart' => ['id' => 'cart-customization', 'title' => 'Cart', 'icon' => 'fa-cart-shopping', 'description' => 'Customize the shopping cart banner and its text.', 'banner' => 'cart_background_image_path'],
    'profile' => ['id' => 'profile-customization', 'title' => 'Profile', 'icon' => 'fa-user', 'description' => 'Customize the customer profile banner and its text.', 'banner' => 'profile_background_image_path'],
    'topup' => ['id' => 'topup-customization', 'title' => 'Wallet top-up', 'icon' => 'fa-wallet', 'description' => 'Customize the wallet top-up banner, payment instructions, and related text.', 'banner' => 'topup_background_image_path'],
    'footer' => ['id' => 'footer-customization', 'title' => 'Footer', 'icon' => 'fa-link', 'description' => 'Customize the footer description and contact details. Quick links remain connected to their existing pages.', 'banner' => null],
    'account' => ['id' => 'account-customization', 'title' => 'Login, register, and logout', 'icon' => 'fa-right-to-bracket', 'description' => 'Customize the account screens and their welcome images. Authentication behavior and links remain unchanged.', 'banner' => null],
    'verification' => ['id' => 'email-verification-customization', 'title' => 'Email verification', 'icon' => 'fa-envelope-circle-check', 'description' => 'Customize sign-up and sign-in verification copy, email messages, and the verification screen background. Code expiry, security, and delivery behavior stay unchanged.', 'banner' => null],
];
?>
<?php include 'admin_header.php'; ?>
<main class="page customization-page">
    <header class="customization-intro">
        <div class="customization-intro-icon"><i class="fa-solid fa-palette"></i></div>
        <div class="customization-intro-copy">
            <span class="customization-eyebrow">Brand studio</span>
            <h1>Site Customization</h1>
            <p>Personalize your storefront and admin experience. Changes apply across the system without changing how it works.</p>
        </div>
        <span class="customization-access"><i class="fa-solid fa-shield-halved"></i> Head Admin</span>
    </header>
    <nav class="customization-jump-nav" aria-label="Customization sections">
        <a href="#brand-identity"><i class="fa-solid fa-signature"></i> Brand</a>
        <a href="#system-colors"><i class="fa-solid fa-droplet"></i> Colors</a>
        <a href="#homepage-video"><i class="fa-solid fa-film"></i> Video &amp; loading</a>
        <a href="#home-customization"><i class="fa-solid fa-house"></i> Home</a>
        <a href="#about-customization"><i class="fa-regular fa-circle-question"></i> About</a>
        <a href="#shop-customization"><i class="fa-solid fa-bag-shopping"></i> Shop</a>
        <a href="#contact-customization"><i class="fa-regular fa-envelope"></i> Contact</a>
        <a href="#orders-customization"><i class="fa-solid fa-box"></i> Orders</a>
        <a href="#search-customization"><i class="fa-solid fa-magnifying-glass"></i> Search</a>
        <a href="#cart-customization"><i class="fa-solid fa-cart-shopping"></i> Cart</a>
        <a href="#profile-customization"><i class="fa-regular fa-user"></i> Profile</a>
        <a href="#topup-customization"><i class="fa-solid fa-wallet"></i> Wallet top-up</a>
        <a href="#footer-customization"><i class="fa-solid fa-link"></i> Footer</a>
        <a href="#account-customization"><i class="fa-solid fa-right-to-bracket"></i> Account screens</a>
        <a href="#email-verification-customization"><i class="fa-solid fa-envelope-circle-check"></i> Email verification</a>
        <a href="#chatbot-settings"><i class="fa-solid fa-comments"></i> Chatbots</a>
    </nav>
    <?php if ($message !== ''): ?>
        <div class="customization-message <?php echo $messageType === 'error' ? 'error' : ''; ?>" role="status"><?php echo escapeOutput($message); ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="customization-form">
        <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
        <section class="customization-card" id="brand-identity">
            <h2><i class="fa-solid fa-signature"></i> Brand identity</h2>
            <p class="customization-help">Set the name and logo customers see across the site.</p>
            <label>Logo text <input type="text" name="brand_name" maxlength="60" required value="<?php echo escapeOutput($settings['brand_name']); ?>"></label>
            <label>Logo tagline <input type="text" name="brand_tagline" maxlength="80" value="<?php echo escapeOutput($settings['brand_tagline']); ?>"></label>
            <label>Logo picture <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"></label>
            <div class="customization-preview-row"><img class="customization-logo-preview" src="<?php echo escapeOutput($logoUrl); ?>" alt="Current logo"><span>Current logo</span></div>
            <label class="customization-check"><input type="checkbox" name="reset_logo" value="1"> Restore the default logo</label>
        </section>
        <section class="customization-card customization-about-team" id="about-customization">
            <h2><i class="fa-solid fa-circle-question"></i> About page</h2>
            <p class="customization-help">Customize About page text, banner, feature images, story-card images, quick-facts image, and team profiles.</p>
            <fieldset class="customization-team-member customization-field-group">
                <legend>About page text</legend>
                <?php foreach ($contentFieldsByPage['about'] as $key => $field):
                    $fieldValue = $settings['content_texts'][$key] ?? $field[1];
                ?>
                    <label><?php echo escapeOutput($field[0]); ?>
                        <?php if (strlen($field[1]) > 90): ?>
                            <textarea name="content_texts[<?php echo escapeOutput($key); ?>]" maxlength="1000" rows="3"><?php echo escapeOutput($fieldValue); ?></textarea>
                        <?php else: ?>
                            <input type="text" name="content_texts[<?php echo escapeOutput($key); ?>]" maxlength="1000" value="<?php echo escapeOutput($fieldValue); ?>">
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <?php $aboutHeroBanner = $pageBackgroundImages['about_hero_image_path']; ?>
            <div class="customization-page-background-setting">
                <label><?php echo escapeOutput($aboutHeroBanner['title']); ?>
                    <input type="file" name="<?php echo escapeOutput($aboutHeroBanner['input']); ?>" accept="image/jpeg,image/png,image/webp">
                </label>
                <div class="customization-page-background-preview">
                    <img src="<?php echo escapeOutput($aboutHeroBanner['url']); ?>" alt="<?php echo escapeOutput($aboutHeroBanner['title']); ?> preview">
                    <span>Current banner image</span>
                </div>
                <label class="customization-check"><input type="checkbox" name="reset_<?php echo escapeOutput($aboutHeroBanner['input']); ?>" value="1"> Restore default banner</label>
            </div>
            <div class="customization-auth-image-grid">
                <?php foreach ([
                    'about_cafe_image_path' => ['title' => 'Featured cafe image', 'input' => 'about_cafe_image', 'url' => $aboutCafeImageUrl, 'default' => 'images/promo-cafe.png'],
                    'about_product_image_path' => ['title' => 'Featured product image', 'input' => 'about_product_image', 'url' => $aboutProductImageUrl, 'default' => 'images/promo-product.png'],
                ] as $settingKey => $imageConfig): ?>
                    <div class="customization-image-setting">
                        <label><?php echo escapeOutput($imageConfig['title']); ?>
                            <input type="file" name="<?php echo escapeOutput($imageConfig['input']); ?>" accept="image/jpeg,image/png,image/webp">
                        </label>
                        <div class="customization-preview-row">
                            <img class="customization-auth-preview" src="<?php echo escapeOutput($imageConfig['url']); ?>" alt="<?php echo escapeOutput($imageConfig['title']); ?> preview">
                            <span>Current image</span>
                        </div>
                        <label class="customization-check"><input type="checkbox" name="reset_<?php echo escapeOutput($imageConfig['input']); ?>" value="1"> Restore default</label>
                    </div>
                <?php endforeach; ?>
            </div>
            <h3>About page images</h3>
            <p class="customization-help">Upload images for the three About page cards and the Quick Facts panel. Leave an upload empty to keep its current image.</p>
            <div class="customization-auth-image-grid">
                <?php foreach ($aboutImpactImages as $settingKey => $imageConfig): ?>
                    <div class="customization-image-setting">
                        <label><?php echo escapeOutput($imageConfig['title']); ?>
                            <input type="file" name="<?php echo escapeOutput($imageConfig['input']); ?>" accept="image/jpeg,image/png,image/webp">
                        </label>
                        <div class="customization-preview-row">
                            <img class="customization-auth-preview" src="<?php echo escapeOutput($imageConfig['url']); ?>" alt="<?php echo escapeOutput($imageConfig['title']); ?> preview">
                            <span>Current image</span>
                        </div>
                        <label class="customization-check"><input type="checkbox" name="reset_<?php echo escapeOutput($imageConfig['input']); ?>" value="1"> Restore default</label>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="customization-team-grid" id="customization-team-grid">
                <?php foreach ($teamMembers as $index => $member):
                    $defaultTeamMember = site_customization_default_team_members()[$index] ?? ['image' => 'images/logos.png'];
                    $teamImageUrl = site_customization_auth_image_url($member['image'], $defaultTeamMember['image']);
                ?>
                    <fieldset class="customization-team-member" data-team-member data-default-image="<?php echo escapeOutput($defaultTeamMember['image']); ?>">
                        <legend>Team member</legend>
                        <label>Name <input type="text" name="team_name[<?php echo $index; ?>]" maxlength="100" required value="<?php echo escapeOutput($member['name']); ?>"></label>
                        <label>Role <input type="text" name="team_role[<?php echo $index; ?>]" maxlength="60" required value="<?php echo escapeOutput($member['role']); ?>"></label>
                        <label>Bio <textarea name="team_bio[<?php echo $index; ?>]" maxlength="250" rows="3" required><?php echo escapeOutput($member['bio']); ?></textarea></label>
                        <label>Profile picture <input type="file" name="team_photo[<?php echo $index; ?>]" accept="image/jpeg,image/png,image/webp"></label>
                        <div class="customization-preview-row">
                            <img class="customization-team-preview" src="<?php echo escapeOutput($teamImageUrl); ?>" data-original-src="<?php echo escapeOutput($teamImageUrl); ?>" alt="<?php echo escapeOutput($member['name']); ?> current profile picture">
                            <span>Current profile picture</span>
                        </div>
                        <label class="customization-check"><input type="checkbox" name="reset_team_photo[<?php echo $index; ?>]" value="1" data-team-reset> Restore default picture</label>
                        <button class="customization-team-remove" type="button" data-remove-team-member><i class="fa-solid fa-trash-can"></i> Remove member</button>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <div class="customization-team-actions">
                <button class="customization-team-add" type="button" id="add-team-member"><i class="fa-solid fa-user-plus"></i> Add team member</button>
                <span id="team-member-count" role="status"><?php echo count($teamMembers); ?> of 30 team members</span>
            </div>
            <template id="team-member-template">
                <fieldset class="customization-team-member" data-team-member data-default-image="images/logos.png">
                    <legend>Team member</legend>
                    <label>Name <input type="text" name="team_name[__INDEX__]" maxlength="100" required></label>
                    <label>Role <input type="text" name="team_role[__INDEX__]" maxlength="60" required></label>
                    <label>Bio <textarea name="team_bio[__INDEX__]" maxlength="250" rows="3" required></textarea></label>
                    <label>Profile picture <input type="file" name="team_photo[__INDEX__]" accept="image/jpeg,image/png,image/webp"></label>
                    <div class="customization-preview-row">
                        <img class="customization-team-preview" src="images/logos.png" data-original-src="images/logos.png" alt="Team member image preview">
                        <span>Current profile picture</span>
                    </div>
                    <label class="customization-check"><input type="checkbox" name="reset_team_photo[__INDEX__]" value="1" data-team-reset> Restore default picture</label>
                    <button class="customization-team-remove" type="button" data-remove-team-member><i class="fa-solid fa-trash-can"></i> Remove member</button>
                </fieldset>
            </template>
            <small>Images must be JPG, PNG, or WebP and no larger than 5 MB. You can add up to 30 team members.</small>
        </section>
        <?php foreach ($customizationPages as $pageKey => $page):
            if ($pageKey === 'about') {
                continue;
            }
        ?>
            <section class="customization-card customization-page-card" id="<?php echo escapeOutput($page['id']); ?>">
                <h2><i class="fa-solid <?php echo escapeOutput($page['icon']); ?>"></i> <?php echo escapeOutput($page['title']); ?></h2>
                <p class="customization-help"><?php echo escapeOutput($page['description']); ?></p>
                <?php if ($contentFieldsByPage[$pageKey] !== []): ?>
                    <fieldset class="customization-team-member customization-field-group">
                        <legend><?php echo escapeOutput($page['title']); ?> text</legend>
                        <?php foreach ($contentFieldsByPage[$pageKey] as $key => $field):
                            $fieldValue = $settings['content_texts'][$key] ?? $field[1];
                        ?>
                            <label><?php echo escapeOutput($field[0]); ?>
                                <?php if (strlen($field[1]) > 90): ?>
                                    <textarea name="content_texts[<?php echo escapeOutput($key); ?>]" maxlength="1000" rows="3"><?php echo escapeOutput($fieldValue); ?></textarea>
                                <?php else: ?>
                                    <input type="text" name="content_texts[<?php echo escapeOutput($key); ?>]" maxlength="1000" value="<?php echo escapeOutput($fieldValue); ?>">
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <?php if ($page['banner'] !== null):
                    $imageConfig = $pageBackgroundImages[$page['banner']];
                ?>
                    <div class="customization-page-background-setting">
                        <label><?php echo escapeOutput($imageConfig['title']); ?>
                            <input type="file" name="<?php echo escapeOutput($imageConfig['input']); ?>" accept="image/jpeg,image/png,image/webp">
                        </label>
                        <div class="customization-page-background-preview">
                            <?php if ($imageConfig['url'] !== ''): ?>
                                <img src="<?php echo escapeOutput($imageConfig['url']); ?>" alt="<?php echo escapeOutput($imageConfig['title']); ?> preview">
                                <span>Current banner image</span>
                            <?php else: ?>
                                <div class="customization-page-background-empty"><i class="fa-regular fa-image"></i><span>Using default banner</span></div>
                            <?php endif; ?>
                        </div>
                        <label class="customization-check"><input type="checkbox" name="reset_<?php echo escapeOutput($imageConfig['input']); ?>" value="1"> Restore default banner</label>
                    </div>
                <?php endif; ?>

                <?php if ($pageKey === 'verification'):
                    $verificationBackgroundUrl = site_customization_auth_image_url(
                        $settings['email_verification_background_image_path'],
                        ''
                    );
                ?>
                    <div class="customization-page-background-setting">
                        <label>Verification screen background image
                            <input type="file" name="email_verification_background" accept="image/jpeg,image/png,image/webp">
                        </label>
                        <div class="customization-page-background-preview">
                            <?php if ($verificationBackgroundUrl !== ''): ?>
                                <img src="<?php echo escapeOutput($verificationBackgroundUrl); ?>" alt="Verification background preview">
                                <span>Current background image</span>
                            <?php else: ?>
                                <div class="customization-page-background-empty"><i class="fa-regular fa-image"></i><span>Using your system background color</span></div>
                            <?php endif; ?>
                        </div>
                        <label class="customization-check"><input type="checkbox" name="reset_email_verification_background" value="1"> Restore system background color</label>
                        <small>JPG, PNG, or WebP; maximum 5 MB. This image appears behind the sign-up and sign-in code screens.</small>
                    </div>
                <?php endif; ?>

                <?php if ($pageKey === 'home'): ?>
                    <h3>Homepage About carousel images</h3>
                    <p class="customization-help">Leave an upload empty to keep its current image.</p>
                    <div class="customization-auth-image-grid">
                        <?php foreach ($homeCarouselImages as $imageConfig): ?>
                            <div class="customization-image-setting">
                                <label><?php echo escapeOutput($imageConfig['title']); ?>
                                    <input type="file" name="<?php echo escapeOutput($imageConfig['input']); ?>" accept="image/jpeg,image/png,image/webp">
                                </label>
                                <div class="customization-preview-row">
                                    <img class="customization-auth-preview" src="<?php echo escapeOutput($imageConfig['url']); ?>" alt="<?php echo escapeOutput($imageConfig['title']); ?> preview">
                                    <span>Current image</span>
                                </div>
                                <label class="customization-check"><input type="checkbox" name="reset_<?php echo escapeOutput($imageConfig['input']); ?>" value="1"> Restore default</label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php
                    $showcaseImageUrl = site_customization_auth_image_url($settings['showcase_image_path'], 'images/coffee-splash.png');
                    ?>
                    <h3>Homepage featured section</h3>
                    <p class="customization-help">Its background and text automatically use your system colors.</p>
                    <label>Featured image <input type="file" name="showcase_image" accept="image/jpeg,image/png,image/webp"></label>
                    <div class="customization-preview-row"><img class="customization-auth-preview customization-showcase-preview" src="<?php echo escapeOutput($showcaseImageUrl); ?>" alt="Featured section image preview"><span>Current image</span></div>
                    <label class="customization-check"><input type="checkbox" name="reset_showcase_image" value="1"> Restore default featured image</label>
                <?php endif; ?>

                <?php if ($pageKey === 'account'): ?>
                    <div class="customization-auth-image-grid">
                        <?php foreach ($authImages as $settingKey => $imageConfig):
                            $imageUrl = site_customization_auth_image_url($settings[$settingKey], $imageConfig['default']);
                        ?>
                            <div class="customization-image-setting">
                                <label><?php echo escapeOutput($imageConfig['title']); ?>
                                    <input type="file" name="<?php echo escapeOutput($imageConfig['input']); ?>" accept="image/jpeg,image/png,image/webp">
                                </label>
                                <div class="customization-preview-row">
                                    <img class="customization-auth-preview" src="<?php echo escapeOutput($imageUrl); ?>" alt="<?php echo escapeOutput($imageConfig['title']); ?> preview">
                                    <span>Current picture</span>
                                </div>
                                <label class="customization-check"><input type="checkbox" name="reset_<?php echo escapeOutput($imageConfig['input']); ?>" value="1"> Restore default</label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small>Images must be JPG, PNG, or WebP and no larger than 5 MB.</small>
                <?php endif; ?>

                <?php if ($pageKey === 'footer'): ?>
                    <p class="customization-help">Quick links and their destinations stay fixed so they continue to work as expected.</p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
        <section class="customization-card customization-colors" id="system-colors">
            <h2><i class="fa-solid fa-droplet"></i> System colors</h2>
            <p class="customization-help customization-colors-intro">Use the color swatch or type a six-digit hex code (for example, #8B4A2F). Keep text and background colors easy to read together.</p>
            <?php
            $colorFields = [
                'primary_color' => [
                    'label' => 'Primary color',
                    'description' => 'Main buttons, links, highlights, and active controls.',
                ],
                'secondary_color' => [
                    'label' => 'Secondary color',
                    'description' => 'Deep brand color used by headings and dark accents.',
                ],
                'accent_color' => [
                    'label' => 'Accent color',
                    'description' => 'Supporting color for descriptions and secondary details.',
                ],
                'page_background' => [
                    'label' => 'Page background',
                    'description' => 'The large background behind page content.',
                ],
                'surface_color' => [
                    'label' => 'Forms and panels',
                    'description' => 'Cards, forms, menus, and other content surfaces.',
                ],
                'text_color' => [
                    'label' => 'Text color',
                    'description' => 'General text on pages; check contrast on light surfaces.',
                ],
                'navbar_background' => [
                    'label' => 'Navbar background',
                    'description' => 'Background of the customer and admin navigation bars.',
                ],
                'navbar_text' => [
                    'label' => 'Navbar text',
                    'description' => 'Text and icons in the navigation bars; contrast it with the navbar background.',
                ],
                'navbar_hover_color' => [
                    'label' => 'Navbar hover background',
                    'description' => 'Background behind hovered and current links in the customer and admin navigation bars.',
                ],
                'navbar_hover_text_color' => [
                    'label' => 'Navbar hover text',
                    'description' => 'Text and icon color for hovered and current navigation links.',
                ],
                'navbar_hover_border_color' => [
                    'label' => 'Navbar hover border',
                    'description' => 'Border color for hovered and current navigation links.',
                ],
                'order_header_text_color' => [
                    'label' => 'Order card header text',
                    'description' => 'Customer and admin order names, order numbers, dates, and totals shown over the colored order header.',
                ],
            ];
            foreach ($colorFields as $key => $colorInfo):
            ?>
                <div class="customization-color-setting">
                    <label for="color-<?php echo escapeOutput($key); ?>"><?php echo escapeOutput($colorInfo['label']); ?></label>
                    <p><?php echo escapeOutput($colorInfo['description']); ?></p>
                    <div class="customization-color-control">
                        <input id="color-picker-<?php echo escapeOutput($key); ?>" type="color" value="<?php echo escapeOutput($settings[$key]); ?>" aria-label="Pick <?php echo escapeOutput($colorInfo['label']); ?>">
                        <input id="color-<?php echo escapeOutput($key); ?>" class="customization-hex-input" type="text" name="<?php echo escapeOutput($key); ?>" value="<?php echo escapeOutput(strtoupper($settings[$key])); ?>" pattern="#[0-9A-Fa-f]{6}" maxlength="7" placeholder="#8B4A2F" autocomplete="off" spellcheck="false" aria-label="<?php echo escapeOutput($colorInfo['label']); ?> hex code" required>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
        <div class="customization-media-row">
            <section class="customization-card customization-homepage-video" id="homepage-video">
                <h2><i class="fa-solid fa-film"></i> Homepage background video</h2>
                <p class="customization-help">Choose a video to play behind the homepage welcome message. Leave the upload empty to keep the current video.</p>
                <label for="hero-media">Upload a video <input id="hero-media" type="file" name="hero_media" accept="video/mp4,video/webm"></label>
                <?php if ($mediaUrl !== null): ?>
                    <p class="customization-current-media"><i class="fa-solid fa-circle-check"></i> Your custom homepage video is active.</p>
                    <video class="customization-video-preview" controls muted playsinline preload="metadata">
                        <source src="<?php echo escapeOutput($mediaUrl); ?>" type="<?php echo str_ends_with($mediaUrl, '.mp4') ? 'video/mp4' : 'video/webm'; ?>">
                    </video>
                <?php elseif ($hasLegacyHeroImage): ?>
                    <p class="customization-legacy-notice"><i class="fa-solid fa-triangle-exclamation"></i> A previous image selection is no longer used. Upload a video or restore the default video.</p>
                <?php else: ?>
                    <p class="customization-current-media"><i class="fa-solid fa-circle-check"></i> Using the included default video.</p>
                <?php endif; ?>
                <label class="customization-check"><input type="checkbox" name="reset_hero_media" value="1"> Restore the default homepage video</label>
                <small>Accepted formats: MP4 or WebM. Maximum file size: 50 MB.</small>
            </section>
            <section class="customization-card customization-loading-card" id="loading-animation">
                <h2><i class="fa-solid fa-spinner"></i> Loading screen</h2>
                <p class="customization-help">Customize the login and logout loading style and the text shown while pages load or sign-in is in progress. The screen uses your current system colors, logo, and brand name.</p>
                <fieldset class="customization-team-member customization-field-group">
                    <legend>Loading screen text</legend>
                    <?php foreach ($contentFieldsByPage['loading'] as $key => $field):
                        $fieldValue = $settings['content_texts'][$key] ?? $field[1];
                    ?>
                        <label><?php echo escapeOutput($field[0]); ?>
                            <input type="text" name="content_texts[<?php echo escapeOutput($key); ?>]" maxlength="1000" value="<?php echo escapeOutput($fieldValue); ?>">
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <label for="loading-style">Loading style
                    <select id="loading-style" name="loading_style">
                        <option value="rings" <?php echo $settings['loading_style'] === 'rings' ? 'selected' : ''; ?>>Coffee rings and progress</option>
                        <option value="spinner" <?php echo $settings['loading_style'] === 'spinner' ? 'selected' : ''; ?>>Simple spinner</option>
                        <option value="dots" <?php echo $settings['loading_style'] === 'dots' ? 'selected' : ''; ?>>Bouncing dots</option>
                        <option value="progress" <?php echo $settings['loading_style'] === 'progress' ? 'selected' : ''; ?>>Progress bar</option>
                        <option value="coffee" <?php echo $settings['loading_style'] === 'coffee' ? 'selected' : ''; ?>>Coffee loader</option>
                        <option value="none" <?php echo $settings['loading_style'] === 'none' ? 'selected' : ''; ?>>No loading animation</option>
                    </select>
                </label>
                <div class="customization-loading-note"><i class="fa-regular fa-lightbulb"></i><span>Pick an animation that fits your brand. Choose “No loading animation” for the fastest, simplest experience.</span></div>
            </section>
        </div>
        <section class="customization-card customization-auth-images" id="chatbot-settings">
            <h2><i class="fa-solid fa-comments"></i> Customer and admin chatbots</h2>
            <p class="customization-help">These colors and the icon are shared by the floating chatbot on the storefront and the assistant in the admin panel.</p>
            <?php $chatbotImageUrl = site_customization_auth_image_url($settings['chatbot_image_path'], 'images/logos.png'); ?>
            <label>Chatbot icon image <input type="file" name="chatbot_image" accept="image/jpeg,image/png,image/webp"></label>
            <div class="customization-preview-row"><img class="customization-logo-preview" src="<?php echo escapeOutput($chatbotImageUrl); ?>" alt="Chatbot icon preview"><span>Current chatbot icon</span></div>
            <label class="customization-check"><input type="checkbox" name="reset_chatbot_image" value="1"> Restore default chatbot icon</label>
            <div class="customization-extra-colors">
                <?php foreach (['chatbot_primary_color' => 'Buttons and header', 'chatbot_panel_color' => 'Panel background', 'chatbot_text_color' => 'Message text', 'chatbot_header_text_color' => 'Header text'] as $key => $label): ?>
                    <div class="customization-extra-color-setting">
                        <label for="color-<?php echo escapeOutput($key); ?>"><?php echo escapeOutput($label); ?></label>
                        <span class="customization-color-control">
                            <input id="color-picker-<?php echo escapeOutput($key); ?>" type="color" value="<?php echo escapeOutput($settings[$key]); ?>" aria-label="Pick <?php echo escapeOutput($label); ?>">
                            <input id="color-<?php echo escapeOutput($key); ?>" class="customization-hex-input" type="text" name="<?php echo escapeOutput($key); ?>" value="<?php echo escapeOutput(strtoupper($settings[$key])); ?>" pattern="#[0-9A-Fa-f]{6}" maxlength="7" placeholder="#C6453E" autocomplete="off" spellcheck="false" aria-label="<?php echo escapeOutput($label); ?> hex code" required>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <div class="customization-save-bar">
            <span><i class="fa-solid fa-circle-info"></i> Changes apply across the storefront and admin panel.</span>
            <button type="submit" class="customization-save"><i class="fa-solid fa-floppy-disk"></i> Save customization</button>
        </div>
    </form>
</main>
<style>
.customization-page{width:min(100%,1380px);max-width:1380px;margin:0 auto;padding:28px clamp(20px,3vw,42px) 56px}
.customization-intro{display:flex;align-items:center;gap:16px;margin:6px 0 26px;padding:22px 24px;border:1px solid color-mix(in srgb,var(--gray-brown) 16%,transparent);border-radius:16px;background:linear-gradient(115deg,var(--site-surface),color-mix(in srgb,var(--site-page-bg) 70%,var(--site-surface)));box-shadow:0 8px 24px rgba(30,20,16,.035)}
.customization-intro-icon{width:54px;height:54px;display:grid;place-items:center;flex:none;border-radius:16px;background:var(--primary-red);color:#fff;font-size:1.35rem;box-shadow:0 8px 20px color-mix(in srgb,var(--primary-red) 22%,transparent)}
.customization-intro h1{color:var(--dark-brown);margin:0 0 5px;font-size:clamp(1.45rem,2.4vw,2rem)}
.customization-intro p,.customization-help{margin:0;color:var(--gray-brown);line-height:1.6}
.customization-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:stretch;gap:22px}
.customization-card{min-width:0;background:var(--site-surface)!important;color:var(--site-text);border:1px solid color-mix(in srgb,var(--gray-brown) 18%,transparent);border-radius:16px;padding:clamp(20px,2.4vw,30px);display:flex;flex-direction:column;gap:15px;box-shadow:0 8px 24px rgba(30,20,16,.035);transition:border-color .18s,box-shadow .18s}
.customization-card:hover{border-color:color-mix(in srgb,var(--primary-red) 24%,var(--gray-brown));box-shadow:0 12px 30px rgba(30,20,16,.06)}
.customization-card>p{line-height:1.55}
.customization-card h2{display:flex;align-items:center;gap:10px;color:var(--dark-brown);font-size:1.12rem;margin:0;padding-bottom:13px;border-bottom:1px solid color-mix(in srgb,var(--gray-brown) 15%,transparent)}
.customization-card h2 i{color:var(--primary-red)}
.customization-card label{display:grid;gap:8px;color:var(--dark-brown);font-weight:700;line-height:1.4}
.customization-card input:not([type=color]):not([type=checkbox]),.customization-card select{width:100%;min-height:44px;padding:10px 12px;border:1px solid #d6c9c1;border-radius:9px;background:var(--site-surface);color:var(--site-text);font:inherit}
.customization-card input[type=file]{padding:9px!important;font-size:.9rem}
.customization-card input:focus-visible,.customization-card select:focus-visible{outline:3px solid color-mix(in srgb,var(--primary-red) 24%,transparent);outline-offset:1px}
.customization-colors{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-content:start;gap:18px 16px}
.customization-colors h2,.customization-colors-intro{grid-column:1/-1}
.customization-color-setting{min-width:0;display:grid;align-content:start;gap:6px}
.customization-color-setting label{display:block;color:var(--dark-brown);font-weight:800}
.customization-color-setting>p{min-height:38px;margin:0;color:var(--gray-brown);font-size:.82rem;line-height:1.45}
.customization-color-control{display:flex;align-items:center;gap:10px;min-width:0;padding:5px 8px;border:1px solid #ded5cf;border-radius:10px;background:var(--site-surface)}
.customization-color-control input[type=color]{width:48px;height:38px;flex:none;padding:2px;border:0;border-radius:6px;background:transparent;cursor:pointer}
.customization-color-control input[type=color]:focus-visible,.customization-hex-input:focus-visible{outline:3px solid color-mix(in srgb,var(--primary-red) 24%,transparent);outline-offset:1px}
.customization-color-control .customization-hex-input{width:100%;min-width:0;min-height:38px;padding:6px 8px;border:1px solid #e6ddd7;border-radius:7px;background:var(--site-surface);color:var(--site-text);font:600 .9rem/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.02em}
.customization-color-control .customization-hex-input[aria-invalid=true]{border-color:#b42318;background:#fff8f7}
.customization-extra-colors{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px 16px}
.customization-extra-color-setting{display:grid;gap:8px;min-width:0}
.customization-extra-color-setting>label{display:block;color:var(--dark-brown);font-weight:700}
.customization-extra-colors .customization-color-control{margin-top:2px}
.customization-check{display:flex!important;align-items:center;gap:9px!important;font-size:.92rem}
.customization-check input{width:17px;height:17px;accent-color:var(--primary-red)}
.customization-preview-row{display:flex;align-items:center;gap:12px;color:var(--gray-brown);font-size:.92rem}
.customization-logo-preview{width:68px;height:68px;object-fit:contain;border:1px solid #eee4dd;border-radius:10px;padding:4px;background:#fff}
.customization-hero-preview{width:100%;max-height:180px;object-fit:cover;border-radius:10px}
.customization-media-row{grid-column:1/-1;display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,.72fr);align-items:stretch;gap:22px}
.customization-media-row .customization-card{height:100%}
.customization-loading-card{justify-content:center}
.customization-current-media{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:var(--gray-brown);font-size:.92rem}
.customization-current-media i{color:#25834b}
.customization-video-preview{width:100%;max-height:220px;aspect-ratio:16/9;object-fit:contain;border-radius:10px;background:#1f1714}
.customization-legacy-notice,.customization-loading-note{display:flex;align-items:flex-start;gap:10px;padding:12px;border-radius:10px;background:color-mix(in srgb,var(--primary-red) 7%,var(--site-surface));color:var(--site-text);font-size:.9rem;line-height:1.5}
.customization-legacy-notice i,.customization-loading-note i{flex:none;margin-top:3px;color:var(--primary-red)}
.customization-auth-images{grid-column:1/-1}
.customization-auth-image-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.customization-image-setting{min-width:0;display:grid;align-content:start;gap:14px;padding:16px;border:1px solid color-mix(in srgb,var(--gray-brown) 16%,transparent);border-radius:12px;background:color-mix(in srgb,var(--site-page-bg) 40%,var(--site-surface))}
.customization-auth-preview{width:100%;height:150px;object-fit:cover;border-radius:9px;background:#eee}
.customization-about-team{grid-column:1/-1}
.customization-team-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.customization-team-member{min-width:0;display:grid;align-content:start;gap:12px;margin:0;padding:12px 16px 16px;border:1px solid color-mix(in srgb,var(--gray-brown) 16%,transparent);border-radius:12px;background:color-mix(in srgb,var(--site-page-bg) 40%,var(--site-surface))}
.customization-team-member legend{margin-left:8px;padding:0 8px;color:var(--dark-brown);font-weight:800}
.customization-team-member textarea{width:100%;min-height:88px;padding:10px 12px;border:1px solid #d6c9c1;border-radius:9px;background:var(--site-surface);color:var(--site-text);font:inherit;resize:vertical}
.customization-team-preview{width:68px;height:68px;object-fit:cover;border:1px solid #eee4dd;border-radius:50%;background:#fff}
.customization-team-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.customization-team-actions>span{color:var(--gray-brown);font-size:.88rem}
.customization-team-add,.customization-team-remove{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:9px 13px;border:1px solid color-mix(in srgb,var(--primary-red) 24%,var(--gray-brown));border-radius:9px;background:var(--site-surface);color:var(--dark-brown);font:inherit;font-size:.88rem;font-weight:800;cursor:pointer}
.customization-team-add{background:var(--primary-red);color:#fff;border-color:var(--primary-red)}
.customization-team-remove{justify-self:start;color:#8a1f19}
.customization-team-add:hover,.customization-team-remove:hover{filter:brightness(.96)}
.customization-save{grid-column:1/-1;min-height:52px;padding:13px 18px;border:0;border-radius:10px;background:var(--primary-red);color:#fff;font:inherit;font-weight:800;cursor:pointer;box-shadow:0 7px 18px color-mix(in srgb,var(--primary-red) 20%,transparent);transition:transform .18s,box-shadow .18s}
.customization-save:hover{transform:translateY(-1px);box-shadow:0 10px 24px color-mix(in srgb,var(--primary-red) 28%,transparent)}
.customization-message{padding:14px;background:#e6f4e6;color:#245b2b;border-radius:9px;margin-bottom:16px}
.customization-message.error{background:#fde8e7;color:#8a1f19}
.customization-intro{position:relative;overflow:hidden;min-height:132px;margin:6px 0 16px;padding:26px 30px;border:1px solid color-mix(in srgb,var(--primary-red) 16%,var(--gray-brown));border-radius:20px;background:radial-gradient(circle at 90% 15%,color-mix(in srgb,var(--primary-red) 12%,transparent),transparent 34%),linear-gradient(115deg,var(--site-surface),color-mix(in srgb,var(--site-page-bg) 76%,var(--site-surface)));box-shadow:0 12px 32px color-mix(in srgb,var(--dark-brown) 8%,transparent)}
.customization-intro-icon{width:62px;height:62px;border-radius:18px;background:linear-gradient(145deg,var(--primary-red),var(--dark-brown));box-shadow:0 10px 24px color-mix(in srgb,var(--primary-red) 24%,transparent)}
.customization-intro-copy{min-width:0}
.customization-eyebrow{display:block;margin-bottom:4px;color:var(--primary-red);font-size:.72rem;font-weight:900;letter-spacing:.14em;text-transform:uppercase}
.customization-intro h1{font-size:clamp(1.55rem,2.6vw,2.15rem);letter-spacing:-.04em}
.customization-access{display:inline-flex;align-items:center;gap:8px;margin-left:auto;padding:9px 13px;border:1px solid color-mix(in srgb,var(--primary-red) 18%,var(--site-surface));border-radius:999px;background:color-mix(in srgb,var(--primary-red) 7%,var(--site-surface));color:var(--dark-brown);font-size:.8rem;font-weight:800;white-space:nowrap}
.customization-access i{color:var(--primary-red)}
.customization-jump-nav{position:sticky;top:10px;z-index:20;display:flex;gap:8px;overflow-x:auto;margin:0 0 22px;padding:10px;border:1px solid color-mix(in srgb,var(--gray-brown) 14%,transparent);border-radius:14px;background:color-mix(in srgb,var(--site-surface) 94%,transparent);box-shadow:0 8px 22px color-mix(in srgb,var(--dark-brown) 8%,transparent);backdrop-filter:blur(14px);scrollbar-width:thin}
.customization-jump-nav a{display:inline-flex;align-items:center;justify-content:center;gap:8px;flex:0 0 auto;min-height:38px;padding:8px 13px;border:1px solid transparent;border-radius:10px;color:var(--gray-brown);font-size:.84rem;font-weight:800;text-decoration:none;transition:background .18s,color .18s,border-color .18s,transform .18s}
.customization-jump-nav a i{color:var(--primary-red)}
.customization-jump-nav a:hover,.customization-jump-nav a:focus-visible{border-color:color-mix(in srgb,var(--primary-red) 20%,var(--site-surface));background:color-mix(in srgb,var(--primary-red) 8%,var(--site-surface));color:var(--dark-brown);outline:none;transform:translateY(-1px)}
.customization-card{border-radius:18px;box-shadow:0 10px 28px color-mix(in srgb,var(--dark-brown) 6%,transparent)}
.customization-card:hover{border-color:color-mix(in srgb,var(--primary-red) 25%,var(--gray-brown));box-shadow:0 14px 34px color-mix(in srgb,var(--dark-brown) 9%,transparent)}
.customization-card h2{font-size:1.16rem;letter-spacing:-.02em}
.customization-card h2 i{width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:color-mix(in srgb,var(--primary-red) 9%,var(--site-surface))}
.customization-card input:not([type=color]):not([type=checkbox]),.customization-card select,.customization-team-member textarea{transition:border-color .18s,box-shadow .18s}
.customization-card input:not([type=color]):not([type=checkbox]):hover,.customization-card select:hover,.customization-team-member textarea:hover{border-color:color-mix(in srgb,var(--primary-red) 38%,var(--gray-brown))}
.customization-card input:focus-visible,.customization-card select:focus-visible,.customization-team-member textarea:focus-visible{border-color:var(--primary-red);box-shadow:0 0 0 4px color-mix(in srgb,var(--primary-red) 12%,transparent)}
.customization-color-setting{padding:12px;border:1px solid color-mix(in srgb,var(--gray-brown) 12%,transparent);border-radius:12px;background:color-mix(in srgb,var(--site-page-bg) 32%,var(--site-surface))}
.customization-color-setting>p{min-height:0}
.customization-color-control{box-shadow:0 2px 6px color-mix(in srgb,var(--dark-brown) 4%,transparent)}
.customization-auth-image-grid,.customization-team-grid{gap:18px}
.customization-image-setting,.customization-team-member,.customization-about-hero-setting{border-radius:15px;box-shadow:0 4px 14px color-mix(in srgb,var(--dark-brown) 4%,transparent)}
.customization-auth-preview,.customization-about-preview{border:1px solid color-mix(in srgb,var(--gray-brown) 14%,transparent)}
.customization-video-preview{border:1px solid color-mix(in srgb,var(--gray-brown) 18%,transparent);box-shadow:0 8px 20px color-mix(in srgb,var(--dark-brown) 12%,transparent)}
.customization-save-bar{position:sticky;bottom:12px;z-index:15;grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 14px 12px 18px;border:1px solid color-mix(in srgb,var(--gray-brown) 16%,transparent);border-radius:15px;background:color-mix(in srgb,var(--site-surface) 94%,transparent);box-shadow:0 12px 32px color-mix(in srgb,var(--dark-brown) 14%,transparent);backdrop-filter:blur(16px)}
.customization-save-bar>span{display:flex;align-items:center;gap:9px;color:var(--gray-brown);font-size:.86rem;font-weight:600;line-height:1.4}
.customization-save-bar>span i{color:var(--primary-red)}
.customization-save{display:inline-flex;align-items:center;justify-content:center;gap:9px;grid-column:auto;min-width:220px;background:linear-gradient(135deg,var(--primary-red),var(--dark-brown));box-shadow:0 7px 18px color-mix(in srgb,var(--primary-red) 22%,transparent)}
.customization-save:hover{box-shadow:0 10px 24px color-mix(in srgb,var(--primary-red) 30%,transparent)}
.customization-form section[id],.customization-media-row section[id]{scroll-margin-top:84px}
#brand-identity,#system-colors{grid-column:1/-1}
#brand-identity{order:1}
#system-colors{order:2}
.customization-media-row{order:3}
#home-customization{order:4}
#about-customization{order:5}
#shop-customization{order:6}
#contact-customization{order:7}
#orders-customization{order:8}
#search-customization{order:9}
#cart-customization{order:10}
#profile-customization{order:11}
#topup-customization{order:12}
#footer-customization{order:13}
#account-customization{order:14}
#chatbot-settings{order:15}
.customization-page-card{grid-column:1/-1}
.customization-save-bar{order:99}
.customization-page-card h3{margin:8px 0 -4px;color:var(--dark-brown);font-size:1rem}
.customization-field-group{grid-template-columns:repeat(2,minmax(0,1fr))}
.customization-field-group legend{grid-column:1/-1}
#brand-identity{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));align-content:start}
#brand-identity h2,#brand-identity>p{grid-column:1/-1}
#brand-identity>label:not(.customization-check){align-content:start}
#brand-identity .customization-preview-row{align-self:center;min-height:68px}
#brand-identity>.customization-check{grid-column:1/-1}
#system-colors{grid-template-columns:repeat(4,minmax(0,1fr));align-content:start}
#system-colors h2,#system-colors .customization-colors-intro{grid-column:1/-1}
#system-colors .customization-color-setting{height:100%}
.customization-about-team>.customization-auth-image-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
.customization-about-team .customization-image-setting{grid-template-columns:minmax(0,1fr) minmax(0,1.15fr);align-items:center;gap:12px 16px}
.customization-about-team .customization-image-setting>label:first-child{grid-column:1/-1}
.customization-about-team .customization-image-setting .customization-preview-row{min-width:0;align-items:center}
.customization-about-team .customization-image-setting .customization-auth-preview{width:72%;flex:0 0 72%;height:clamp(150px,17vw,230px)}
.customization-about-team .customization-image-setting .customization-check{grid-column:1/-1}
.customization-page-backgrounds{grid-column:1/-1}
.customization-page-background-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.customization-page-background-setting{display:grid;align-content:start;gap:12px;min-width:0;padding:16px;border:1px solid color-mix(in srgb,var(--gray-brown) 15%,transparent);border-radius:14px;background:color-mix(in srgb,var(--site-page-bg) 34%,var(--site-surface))}
.customization-page-background-preview{display:flex;align-items:center;gap:10px;min-width:0;color:var(--gray-brown);font-size:.84rem;line-height:1.35}
.customization-page-background-preview img{width:72%;height:130px;flex:0 0 72%;object-fit:cover;border:1px solid color-mix(in srgb,var(--gray-brown) 16%,transparent);border-radius:10px;background:var(--site-page-bg)}
.customization-page-background-empty{display:grid;place-items:center;gap:8px;width:100%;height:130px;padding:12px;border:1px dashed color-mix(in srgb,var(--gray-brown) 30%,transparent);border-radius:10px;background:color-mix(in srgb,var(--site-surface) 75%,transparent);text-align:center}
.customization-page-background-empty i{color:var(--primary-red);font-size:1.4rem}
@media(max-width:720px){.customization-access{display:none}.customization-jump-nav{top:6px;margin-bottom:16px}}
@media(max-width:1050px){#system-colors{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:820px){#brand-identity{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:720px){.customization-about-team .customization-image-setting{grid-template-columns:1fr}.customization-about-team .customization-image-setting>label:first-child,.customization-about-team .customization-image-setting .customization-check{grid-column:auto}.customization-page-background-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.customization-intro{gap:12px;padding:18px}.customization-intro-icon{width:46px;height:46px}.customization-intro p{font-size:.9rem}.customization-jump-nav{margin-right:-2px;margin-left:-2px}.customization-jump-nav a{min-height:36px;padding:7px 10px;font-size:.78rem}.customization-save-bar{bottom:8px;align-items:stretch;flex-direction:column;padding:12px}.customization-save-bar>span{font-size:.8rem}.customization-save{width:100%;min-width:0}#brand-identity,#system-colors{grid-template-columns:1fr}#brand-identity h2,#brand-identity>p,#system-colors h2,#system-colors .customization-colors-intro{grid-column:auto}.customization-about-team>.customization-auth-image-grid{grid-template-columns:1fr}.customization-about-team .customization-auth-preview{height:190px}.customization-page-background-grid{grid-template-columns:1fr}.customization-field-group{grid-template-columns:1fr}}
@media(max-width:1050px){.customization-form{grid-template-columns:1fr}.customization-auth-images{grid-column:auto}.customization-save{grid-column:auto}}
@media(max-width:1050px){.customization-about-team{grid-column:auto}.customization-team-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:820px){.customization-media-row{grid-template-columns:1fr}.customization-media-row .customization-card{height:auto}}
@media(max-width:720px){.customization-auth-image-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.customization-extra-colors{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.customization-page{padding:18px 14px 36px}.customization-intro{align-items:flex-start}.customization-intro-icon{width:44px;height:44px;border-radius:12px}.customization-colors,.customization-auth-image-grid,.customization-extra-colors,.customization-team-grid{grid-template-columns:1fr}.customization-card{padding:17px}.customization-color-setting>p{min-height:0}.customization-auth-preview{height:170px}}
</style>
<script>
(() => {
    const teamGrid = document.getElementById('customization-team-grid');
    const addTeamMember = document.getElementById('add-team-member');
    const teamTemplate = document.getElementById('team-member-template');
    const teamCount = document.getElementById('team-member-count');
    if (!teamGrid || !addTeamMember || !teamTemplate || !teamCount) return;

    const maxMembers = 30;
    const getNextIndex = () => {
        const indexes = Array.from(teamGrid.querySelectorAll('[name^="team_name["]'))
            .map((input) => Number(input.name.match(/\[(\d+)\]$/)?.[1]))
            .filter(Number.isInteger);
        return indexes.length ? Math.max(...indexes) + 1 : 0;
    };
    let nextIndex = getNextIndex();

    const updateTeamCount = () => {
        const members = teamGrid.querySelectorAll('[data-team-member]');
        const count = members.length;
        members.forEach((member, index) => {
            member.querySelector('legend').textContent = `Team member ${index + 1}`;
        });
        teamCount.textContent = `${count} of ${maxMembers} team members`;
        addTeamMember.disabled = count >= maxMembers;
    };

    addTeamMember.addEventListener('click', () => {
        if (teamGrid.querySelectorAll('[data-team-member]').length >= maxMembers) return;
        const member = teamTemplate.content.cloneNode(true);
        member.querySelectorAll('[name]').forEach((input) => {
            input.name = input.name.replace(/__INDEX__/g, String(nextIndex));
        });
        teamGrid.appendChild(member);
        nextIndex += 1;
        updateTeamCount();
        const lastMember = teamGrid.lastElementChild;
        if (lastMember) {
            const nameInput = lastMember.querySelector('input[name^="team_name["]');
            if (nameInput) nameInput.focus();
        }
    });

    teamGrid.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-remove-team-member]');
        if (!removeButton) return;
        const member = removeButton.closest('[data-team-member]');
        const preview = member?.querySelector('.customization-team-preview');
        if (preview?.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
        member?.remove();
        updateTeamCount();
    });

    teamGrid.addEventListener('change', (event) => {
        if (event.target.matches('input[type="file"][name^="team_photo["]')) {
            const file = event.target.files?.[0];
            const preview = event.target.closest('[data-team-member]')?.querySelector('.customization-team-preview');
            const resetCheckbox = event.target.closest('[data-team-member]')?.querySelector('[data-team-reset]');
            if (file && preview) {
                if (preview.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
                preview.dataset.objectUrl = URL.createObjectURL(file);
                preview.src = preview.dataset.objectUrl;
                if (resetCheckbox) resetCheckbox.checked = false;
            }
        }
        if (event.target.matches('[data-team-reset]')) {
            const member = event.target.closest('[data-team-member]');
            const preview = member?.querySelector('.customization-team-preview');
            if (preview) {
                if (preview.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
                preview.dataset.objectUrl = '';
                if (event.target.checked) {
                    const fileInput = member?.querySelector('input[type="file"][name^="team_photo["]');
                    if (fileInput) fileInput.value = '';
                    preview.src = member.dataset.defaultImage || 'images/logos.png';
                } else {
                    preview.src = preview.dataset.originalSrc || member.dataset.defaultImage || 'images/logos.png';
                }
            }
        }
    });

    updateTeamCount();
})();

document.querySelectorAll('.customization-color-control').forEach((control) => {
    const picker = control.querySelector('input[type="color"]');
    const hexInput = control.querySelector('.customization-hex-input');
    const validateHex = () => {
        const valid = /^#[0-9A-Fa-f]{6}$/.test(hexInput.value.trim());
        hexInput.setCustomValidity(valid ? '' : 'Enter a six-digit hex color, for example #8B4A2F.');
        hexInput.setAttribute('aria-invalid', String(!valid));
        if (valid) picker.value = hexInput.value.trim();
        return valid;
    };

    picker.addEventListener('input', () => {
        hexInput.value = picker.value.toUpperCase();
        validateHex();
    });
    hexInput.addEventListener('input', validateHex);
    hexInput.addEventListener('blur', () => {
        const value = hexInput.value.trim();
        if (/^[0-9A-Fa-f]{6}$/.test(value)) hexInput.value = `#${value}`;
        validateHex();
    });
});
</script>
<?php include 'admin_footer.php'; ?>
