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
        $loadingStyle = (string)($_POST['loading_style'] ?? '');
        if (!in_array($loadingStyle, ['rings', 'spinner', 'dots', 'progress', 'coffee', 'none'], true)) {
            throw new RuntimeException('Choose a valid loading animation.');
        }
        $newSettings['loading_style'] = $loadingStyle;
        foreach (['primary_color', 'secondary_color', 'accent_color', 'page_background', 'surface_color', 'text_color', 'navbar_background', 'navbar_text'] as $key) {
            $value = (string)($_POST[$key] ?? '');
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                throw new RuntimeException('Choose a valid six-digit hex color for each color setting.');
            }
            $newSettings[$key] = strtoupper($value);
        }
        foreach ([
            'showcase_background',
            'showcase_heading_color',
            'showcase_text_color',
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

        $oldMedia = $settings['hero_media_path'];
        if (!empty($_POST['reset_hero_media'])) {
            $newSettings['hero_media_path'] = null;
            $newSettings['hero_media_type'] = 'video';
        } else {
            $mediaType = (string)($_POST['hero_media_type'] ?? '');
            if (!in_array($mediaType, ['image', 'video'], true)) {
                throw new RuntimeException('Choose either an image or a video for the homepage background.');
            }
            $uploadedMedia = save_site_customization_upload($_FILES['hero_media'] ?? [], 'hero', __DIR__);
            if ($uploadedMedia !== null) {
                $newUploads[] = $uploadedMedia;
                $expectedType = in_array(pathinfo($uploadedMedia, PATHINFO_EXTENSION), ['mp4', 'webm'], true) ? 'video' : 'image';
                if ($expectedType !== $mediaType) {
                    delete_site_customization_upload($uploadedMedia, __DIR__);
                    throw new RuntimeException('The uploaded homepage media does not match the selected image or video type.');
                }
                $newSettings['hero_media_path'] = $uploadedMedia;
            } elseif ($mediaType !== $settings['hero_media_type']) {
                throw new RuntimeException('Upload a new file when changing the homepage media type.');
            }
            $newSettings['hero_media_type'] = $mediaType;
        }

        save_site_customization($conn, $newSettings, (int)$_SESSION['admin_id']);
        $saveCompleted = true;
        log_admin_activity(
            (int)$_SESSION['admin_id'],
            'Updated Site Customization',
            'Updated site branding, colors, or homepage media.',
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
$mediaUrl = site_customization_media_url($settings['hero_media_path']);
$authImages = [
    'login_image_path' => ['title' => 'Login page picture', 'input' => 'login_image', 'default' => 'images/logback.jpeg'],
    'register_image_path' => ['title' => 'Customer registration picture', 'input' => 'register_image', 'default' => 'images/logback.jpeg'],
    'admin_register_image_path' => ['title' => 'Admin registration picture', 'input' => 'admin_register_image', 'default' => 'images/logback.jpeg'],
];
?>
<?php include 'admin_header.php'; ?>
<main class="page customization-page">
    <header class="customization-intro">
        <div class="customization-intro-icon"><i class="fa-solid fa-palette"></i></div>
        <div>
            <h1>Site Customization</h1>
            <p>Manage your brand, colors, page images, and loading experience in one place.</p>
        </div>
    </header>
    <?php if ($message !== ''): ?>
        <div class="customization-message <?php echo $messageType === 'error' ? 'error' : ''; ?>" role="status"><?php echo escapeOutput($message); ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="customization-form">
        <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
        <section class="customization-card">
            <h2><i class="fa-solid fa-signature"></i> Brand identity</h2>
            <label>Logo text <input type="text" name="brand_name" maxlength="60" required value="<?php echo escapeOutput($settings['brand_name']); ?>"></label>
            <label>Logo tagline <input type="text" name="brand_tagline" maxlength="80" value="<?php echo escapeOutput($settings['brand_tagline']); ?>"></label>
            <label>Logo picture <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"></label>
            <div class="customization-preview-row"><img class="customization-logo-preview" src="<?php echo escapeOutput($logoUrl); ?>" alt="Current logo"><span>Current logo</span></div>
            <label class="customization-check"><input type="checkbox" name="reset_logo" value="1"> Restore the default logo</label>
        </section>
        <section class="customization-card customization-colors">
            <h2><i class="fa-solid fa-droplet"></i> System colors</h2>
            <p class="customization-help customization-colors-intro">Choose a color to update that part of the site. Keep text and background colors easy to read together.</p>
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
            ];
            foreach ($colorFields as $key => $colorInfo):
            ?>
                <div class="customization-color-setting">
                    <label for="color-<?php echo escapeOutput($key); ?>"><?php echo escapeOutput($colorInfo['label']); ?></label>
                    <p><?php echo escapeOutput($colorInfo['description']); ?></p>
                    <div class="customization-color-control">
                        <input id="color-<?php echo escapeOutput($key); ?>" type="color" name="<?php echo escapeOutput($key); ?>" value="<?php echo escapeOutput($settings[$key]); ?>">
                        <output for="color-<?php echo escapeOutput($key); ?>"><?php echo escapeOutput(strtoupper($settings[$key])); ?></output>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
        <section class="customization-card">
            <h2><i class="fa-solid fa-panorama"></i> Homepage background</h2>
            <label>Media type
                <select name="hero_media_type">
                    <option value="video" <?php echo $settings['hero_media_type'] === 'video' ? 'selected' : ''; ?>>Video</option>
                    <option value="image" <?php echo $settings['hero_media_type'] === 'image' ? 'selected' : ''; ?>>Image</option>
                </select>
            </label>
            <label>Upload homepage image/video <input type="file" name="hero_media" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm"></label>
            <p>Current: <?php echo $mediaUrl === null ? 'Default bundled video' : escapeOutput(basename($mediaUrl)); ?></p>
            <?php if ($mediaUrl !== null && $settings['hero_media_type'] === 'image'): ?>
                <img class="customization-hero-preview" src="<?php echo escapeOutput($mediaUrl); ?>" alt="Current homepage background">
            <?php endif; ?>
            <label class="customization-check"><input type="checkbox" name="reset_hero_media" value="1"> Restore the default homepage video</label>
            <small>Images: JPG, PNG, or WebP (5 MB max). Videos: MP4 or WebM (50 MB max).</small>
        </section>
        <section class="customization-card customization-auth-images">
            <h2><i class="fa-regular fa-images"></i> Sign-in and registration pictures</h2>
            <p class="customization-help">Upload a different JPG, PNG, or WebP image for each welcome screen. Images can be up to 5 MB.</p>
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
        </section>
        <section class="customization-card">
            <h2><i class="fa-solid fa-spinner"></i> Loading animation</h2>
            <label>Choose the loading style
                <select name="loading_style">
                    <option value="rings" <?php echo $settings['loading_style'] === 'rings' ? 'selected' : ''; ?>>Coffee rings and progress</option>
                    <option value="spinner" <?php echo $settings['loading_style'] === 'spinner' ? 'selected' : ''; ?>>Simple spinner</option>
                    <option value="dots" <?php echo $settings['loading_style'] === 'dots' ? 'selected' : ''; ?>>Bouncing dots</option>
                    <option value="progress" <?php echo $settings['loading_style'] === 'progress' ? 'selected' : ''; ?>>Progress bar</option>
                    <option value="coffee" <?php echo $settings['loading_style'] === 'coffee' ? 'selected' : ''; ?>>Coffee loader</option>
                    <option value="none" <?php echo $settings['loading_style'] === 'none' ? 'selected' : ''; ?>>No loading animation</option>
                </select>
            </label>
            <p class="customization-help">Used on login and logout screens. The progress bar and dots are also shown on login unless a simpler style is selected.</p>
        </section>
        <section class="customization-card customization-auth-images">
            <h2><i class="fa-solid fa-mug-hot"></i> Homepage featured section</h2>
            <p class="customization-help">This is the “Every Cup Starts with a Smile” section shown below the main homepage banner.</p>
            <?php
            $showcaseImageUrl = site_customization_auth_image_url($settings['showcase_image_path'], 'images/coffee-splash.png');
            ?>
            <label>Featured image <input type="file" name="showcase_image" accept="image/jpeg,image/png,image/webp"></label>
            <div class="customization-preview-row"><img class="customization-auth-preview customization-showcase-preview" src="<?php echo escapeOutput($showcaseImageUrl); ?>" alt="Featured section image preview"><span>Current image</span></div>
            <label class="customization-check"><input type="checkbox" name="reset_showcase_image" value="1"> Restore default featured image</label>
            <div class="customization-extra-colors">
                <?php foreach (['showcase_background' => 'Section background', 'showcase_heading_color' => 'Heading', 'showcase_text_color' => 'Description text'] as $key => $label): ?>
                    <label for="color-<?php echo escapeOutput($key); ?>"><?php echo escapeOutput($label); ?>
                        <span class="customization-color-control"><input id="color-<?php echo escapeOutput($key); ?>" type="color" name="<?php echo escapeOutput($key); ?>" value="<?php echo escapeOutput($settings[$key]); ?>"><output><?php echo escapeOutput(strtoupper($settings[$key])); ?></output></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="customization-card customization-auth-images">
            <h2><i class="fa-solid fa-comments"></i> Customer and admin chatbots</h2>
            <p class="customization-help">These colors and the icon are shared by the floating chatbot on the storefront and the assistant in the admin panel.</p>
            <?php $chatbotImageUrl = site_customization_auth_image_url($settings['chatbot_image_path'], 'images/logos.png'); ?>
            <label>Chatbot icon image <input type="file" name="chatbot_image" accept="image/jpeg,image/png,image/webp"></label>
            <div class="customization-preview-row"><img class="customization-logo-preview" src="<?php echo escapeOutput($chatbotImageUrl); ?>" alt="Chatbot icon preview"><span>Current chatbot icon</span></div>
            <label class="customization-check"><input type="checkbox" name="reset_chatbot_image" value="1"> Restore default chatbot icon</label>
            <div class="customization-extra-colors">
                <?php foreach (['chatbot_primary_color' => 'Buttons and header', 'chatbot_panel_color' => 'Panel background', 'chatbot_text_color' => 'Message text', 'chatbot_header_text_color' => 'Header text'] as $key => $label): ?>
                    <label for="color-<?php echo escapeOutput($key); ?>"><?php echo escapeOutput($label); ?>
                        <span class="customization-color-control"><input id="color-<?php echo escapeOutput($key); ?>" type="color" name="<?php echo escapeOutput($key); ?>" value="<?php echo escapeOutput($settings[$key]); ?>"><output><?php echo escapeOutput(strtoupper($settings[$key])); ?></output></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>
        <button type="submit" class="customization-save">Save customization</button>
    </form>
</main>
<style>
.customization-page{width:min(100%,1380px);max-width:1380px;margin:0 auto;padding:28px clamp(20px,3vw,42px) 56px}
.customization-intro{display:flex;align-items:center;gap:16px;margin:6px 0 26px}
.customization-intro-icon{width:54px;height:54px;display:grid;place-items:center;flex:none;border-radius:16px;background:var(--primary-red);color:#fff;font-size:1.35rem;box-shadow:0 8px 20px color-mix(in srgb,var(--primary-red) 22%,transparent)}
.customization-intro h1{color:var(--dark-brown);margin:0 0 5px;font-size:clamp(1.45rem,2.4vw,2rem)}
.customization-intro p,.customization-help{margin:0;color:var(--gray-brown);line-height:1.6}
.customization-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:stretch;gap:22px}
.customization-card{min-width:0;background:var(--site-surface)!important;color:var(--site-text);border:1px solid color-mix(in srgb,var(--gray-brown) 18%,transparent);border-radius:16px;padding:clamp(20px,2.4vw,30px);display:flex;flex-direction:column;gap:17px;box-shadow:0 8px 24px rgba(30,20,16,.035)}
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
.customization-color-control output{overflow:hidden;color:var(--site-text);font-size:.82rem;font-weight:700;text-overflow:ellipsis}
.customization-extra-colors{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px 16px}
.customization-extra-colors label{min-width:0}
.customization-extra-colors .customization-color-control{margin-top:2px}
.customization-check{display:flex!important;align-items:center;gap:9px!important;font-size:.92rem}
.customization-check input{width:17px;height:17px;accent-color:var(--primary-red)}
.customization-preview-row{display:flex;align-items:center;gap:12px;color:var(--gray-brown);font-size:.92rem}
.customization-logo-preview{width:68px;height:68px;object-fit:contain;border:1px solid #eee4dd;border-radius:10px;padding:4px;background:#fff}
.customization-hero-preview{width:100%;max-height:180px;object-fit:cover;border-radius:10px}
.customization-auth-images{grid-column:1/-1}
.customization-auth-image-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.customization-image-setting{min-width:0;display:grid;align-content:start;gap:14px;padding:16px;border:1px solid color-mix(in srgb,var(--gray-brown) 16%,transparent);border-radius:12px;background:color-mix(in srgb,var(--site-page-bg) 40%,var(--site-surface))}
.customization-auth-preview{width:100%;height:150px;object-fit:cover;border-radius:9px;background:#eee}
.customization-save{grid-column:1/-1;min-height:52px;padding:13px 18px;border:0;border-radius:10px;background:var(--primary-red);color:#fff;font:inherit;font-weight:800;cursor:pointer;box-shadow:0 7px 18px color-mix(in srgb,var(--primary-red) 20%,transparent);transition:transform .18s,box-shadow .18s}
.customization-save:hover{transform:translateY(-1px);box-shadow:0 10px 24px color-mix(in srgb,var(--primary-red) 28%,transparent)}
.customization-message{padding:14px;background:#e6f4e6;color:#245b2b;border-radius:9px;margin-bottom:16px}
.customization-message.error{background:#fde8e7;color:#8a1f19}
@media(max-width:1050px){.customization-form{grid-template-columns:1fr}.customization-auth-images{grid-column:auto}.customization-save{grid-column:auto}}
@media(max-width:720px){.customization-auth-image-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.customization-extra-colors{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.customization-page{padding:18px 14px 36px}.customization-intro{align-items:flex-start}.customization-intro-icon{width:44px;height:44px;border-radius:12px}.customization-colors,.customization-auth-image-grid,.customization-extra-colors{grid-template-columns:1fr}.customization-card{padding:17px}.customization-color-setting>p{min-height:0}.customization-auth-preview{height:170px}}
</style>
<script>
document.querySelectorAll('.customization-color-control input[type="color"]').forEach((input) => {
    const output = input.parentElement.querySelector('output');
    input.addEventListener('input', () => {
        output.value = input.value.toUpperCase();
        output.textContent = input.value.toUpperCase();
    });
});
</script>
<?php include 'admin_footer.php'; ?>
