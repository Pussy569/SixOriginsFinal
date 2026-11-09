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
        foreach (['primary_color', 'secondary_color', 'accent_color', 'page_background', 'surface_color', 'text_color', 'navbar_background', 'navbar_text'] as $key) {
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
            ['brand_name' => $newSettings['brand_name'], 'hero_media_type' => $newSettings['hero_media_type']]
        );
        if ($oldLogo !== $newSettings['logo_path'] && str_starts_with((string)$oldLogo, 'images/site-customization/')) {
            delete_site_customization_upload(substr($oldLogo, strlen('images/')), __DIR__);
        }
        if ($oldMedia !== $newSettings['hero_media_path']) {
            delete_site_customization_upload($oldMedia, __DIR__);
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
?>
<?php include 'admin_header.php'; ?>
<main class="page customization-page">
    <h1>Site Customization</h1>
    <p>Update your storefront branding, theme colors, and homepage background without changing application behavior.</p>
    <?php if ($message !== ''): ?>
        <div class="customization-message <?php echo $messageType === 'error' ? 'error' : ''; ?>" role="status"><?php echo escapeOutput($message); ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="customization-form">
        <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
        <section class="customization-card">
            <h2>Brand identity</h2>
            <label>Logo text <input type="text" name="brand_name" maxlength="60" required value="<?php echo escapeOutput($settings['brand_name']); ?>"></label>
            <label>Logo tagline <input type="text" name="brand_tagline" maxlength="80" value="<?php echo escapeOutput($settings['brand_tagline']); ?>"></label>
            <label>Logo picture <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"></label>
            <p>Current logo: <img class="customization-logo-preview" src="<?php echo escapeOutput($logoUrl); ?>" alt="Current logo"></p>
            <label class="customization-check"><input type="checkbox" name="reset_logo" value="1"> Restore the default logo</label>
        </section>
        <section class="customization-card customization-colors">
            <h2>System colors</h2>
            <?php
            $colorFields = [
                'primary_color' => 'Primary color',
                'secondary_color' => 'Secondary color',
                'accent_color' => 'Accent color',
                'page_background' => 'Page background',
                'surface_color' => 'Forms and panels',
                'text_color' => 'Text color',
                'navbar_background' => 'Navbar background',
                'navbar_text' => 'Navbar text',
            ];
            foreach ($colorFields as $key => $label):
            ?>
                <label><?php echo escapeOutput($label); ?>
                    <input type="color" name="<?php echo escapeOutput($key); ?>" value="<?php echo escapeOutput($settings[$key]); ?>">
                </label>
            <?php endforeach; ?>
        </section>
        <section class="customization-card">
            <h2>Homepage background</h2>
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
        <button type="submit" class="customization-save">Save customization</button>
    </form>
</main>
<style>
.customization-page{max-width:1000px}.customization-page h1{color:var(--dark-brown);margin-bottom:8px}.customization-page>p{margin-bottom:24px;color:var(--gray-brown)}
.customization-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.customization-card{background:var(--site-surface)!important;color:var(--site-text);border:1px solid #eadfd7;border-radius:16px;padding:22px;display:grid;gap:14px}.customization-card h2{color:var(--dark-brown);font-size:1.15rem}.customization-card label{display:grid;gap:7px;color:var(--dark-brown);font-weight:700}.customization-card input:not([type=color]):not([type=checkbox]),.customization-card select{width:100%;padding:11px;border:1px solid #d6c9c1;border-radius:8px;font:inherit}.customization-colors{grid-template-columns:repeat(2,minmax(0,1fr));align-content:start}.customization-colors h2{grid-column:1/-1}.customization-colors input[type=color]{width:100%;height:44px;border:1px solid #ddd;border-radius:8px}.customization-check{display:flex!important;align-items:center;gap:8px!important}.customization-logo-preview{width:80px;height:80px;object-fit:contain}.customization-hero-preview{width:100%;max-height:180px;object-fit:cover}.customization-save{grid-column:1/-1;padding:13px;border:0;border-radius:10px;background:var(--primary-red);color:#fff;font:inherit;font-weight:800;cursor:pointer}.customization-message{padding:14px;background:#e6f4e6;color:#245b2b;border-radius:8px;margin-bottom:16px}.customization-message.error{background:#fde8e7;color:#8a1f19}@media(max-width:700px){.customization-form{grid-template-columns:1fr}.customization-colors{grid-template-columns:1fr 1fr}.customization-save{grid-column:auto}}
</style>
<?php include 'admin_footer.php'; ?>
