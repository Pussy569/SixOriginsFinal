<?php
include 'config.php';

// config.php may already start the session, so only start it if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// assign user id (logged in or guest) - same logic as before
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
} else {
    if (!isset($_SESSION['guest_id'])) {
        $_SESSION['guest_id'] = 'guest_' . session_id();
    }
    $user_id = $_SESSION['guest_id'];
}

// Static product cards (visual only) - add or remove items here
$base_products = [
    ['name' => 'Strawberry drink',  'price' => 120, 'image' => 'drink.jpg', 'link' => 'Item.php'],
    ['name' => 'Strawberry drink', 'price' => 130, 'image' => 'drink.jpg',    'link' => 'Item.php'],
];
// the original page showed each product 3 times; remove this line to show them once
$products = array_merge($base_products, $base_products, $base_products);
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8" />
   <meta name="viewport" content="width=device-width,initial-scale=1" />
   <title>Coffee — Six Origins Cafe</title>
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
   <style>
      /* All page classes are prefixed with "sx-" so they can never clash with header.php */
      :root {
        --primary-red: #C6453E;
        --dark-brown: #5E1F13;
        --gray-brown: #664C47;
        --light-cream: #FFF2E0;
        --white: #FFFFFF;
        --line: #F0E6D8;
        --radius: 16px;
        --shadow: 0 8px 24px rgba(94, 31, 19, 0.08);
        --shadow-hover: 0 12px 36px rgba(94, 31, 19, 0.14);
        --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        --max-width: 1200px;
      }

      .sx-page, .sx-page *, .sx-page *::before, .sx-page *::after { box-sizing: border-box; }

      /* FIX: no height:100% / overflow-x:hidden on html+body.
         That made <body> the scroll container, which broke the sticky header
         and the header's scroll-shrink effect. "clip" prevents sideways
         scroll without creating a scroll container. */
      body.sx-body {
        min-height: 100vh;
        background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
        color: var(--dark-brown);
        font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        -webkit-font-smoothing: antialiased;
        line-height: 1.6;
        overflow-x: clip;
      }

      .sx-page {
        max-width: var(--max-width);
        margin: 0 auto;
        padding: 24px 24px 56px;
        width: 100%;
      }

      /* Breadcrumb */
      .sx-breadcrumb {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        font-size: 0.92rem;
        color: var(--gray-brown);
        margin-bottom: 18px;
        font-weight: 500;
      }
      .sx-breadcrumb a { color: var(--primary-red); text-decoration: none; font-weight: 700; }
      .sx-breadcrumb a:hover { color: var(--dark-brown); text-decoration: underline; }
      .sx-breadcrumb strong { color: var(--dark-brown); }

      /* Hero */
      .sx-hero {
        display: flex;
        gap: 28px;
        align-items: center;
        background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
        padding: 24px;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1.5px solid var(--line);
        margin-bottom: 24px;
      }
      .sx-hero-image { flex: 0 0 260px; }
      .sx-hero-image img {
        width: 100%;
        height: 150px;
        object-fit: cover;
        border-radius: 12px;
        border: 1.5px solid var(--line);
        display: block;
      }
      .sx-hero h1 {
        font-size: 2.1rem;
        line-height: 1.15;
        margin-bottom: 8px;
        color: var(--dark-brown);
        font-weight: 900;
        letter-spacing: -0.5px;
      }
      .sx-hero p { color: var(--gray-brown); font-size: 1rem; font-weight: 500; }

      /* Category tabs - scroll sideways on small screens instead of wrapping/overflowing */
      .sx-tabs {
        display: flex;
        gap: 10px;
        justify-content: center;
        margin: 0 0 24px;
        overflow-x: auto;
        padding: 4px 2px 8px;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
      }
      .sx-tabs::-webkit-scrollbar { display: none; }

      .sx-tab {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--white);
        border: 1.5px solid var(--line);
        padding: 10px 20px;
        border-radius: 999px;
        font-weight: 700;
        font-size: 0.95rem;
        color: var(--dark-brown);
        text-decoration: none;
        transition: var(--transition);
      }
      .sx-tab:hover { border-color: var(--primary-red); color: var(--primary-red); }
      .sx-tab.active {
        background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
        color: #fff;
        border-color: var(--primary-red);
        box-shadow: 0 6px 16px rgba(198, 69, 62, 0.25);
      }

      .sx-count {
        color: var(--gray-brown);
        font-weight: 600;
        font-size: 0.92rem;
        margin-bottom: 14px;
      }

      /* Grid */
      .sx-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 250px), 1fr));
        gap: 24px;
      }

      .sx-card {
        position: relative;
        display: flex;
        flex-direction: column;
        background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
        border-radius: var(--radius);
        border: 1.5px solid var(--line);
        box-shadow: var(--shadow);
        overflow: hidden;
        transition: var(--transition);
      }
      .sx-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-hover);
        border-color: var(--primary-red);
      }

      .sx-img {
        position: relative;
        display: block;
        aspect-ratio: 4 / 3;
        overflow: hidden;
        background: var(--light-cream);
      }
      .sx-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.4s ease;
      }
      .sx-card:hover .sx-img img { transform: scale(1.05); }

      .sx-body-card {
        display: flex;
        flex-direction: column;
        gap: 12px;
        padding: 16px 18px 18px;
        flex: 1;
      }
      .sx-name {
        font-weight: 800;
        font-size: 1.1rem;
        line-height: 1.3;
        color: var(--dark-brown);
      }
      .sx-price { font-weight: 900; color: var(--primary-red); font-size: 1.25rem; }

      .sx-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-top: auto;
      }
      .sx-btns { display: flex; gap: 8px; }

      .sx-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 10px 16px;
        border-radius: 10px;
        border: 1.5px solid transparent;
        font-weight: 800;
        font-size: 0.9rem;
        text-decoration: none;
        cursor: pointer;
        transition: var(--transition);
        font-family: inherit;
      }
      .sx-btn-primary {
        background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
        color: #fff;
        box-shadow: 0 6px 16px rgba(198, 69, 62, 0.25);
      }
      .sx-btn-primary:hover { background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%); transform: translateY(-2px); }
      .sx-btn-outline { background: var(--white); border-color: var(--line); color: var(--dark-brown); }
      .sx-btn-outline:hover { border-color: var(--primary-red); color: var(--primary-red); background: var(--light-cream); }

      .sx-tab:focus-visible, .sx-btn:focus-visible, .sx-img:focus-visible {
        outline: 3px solid rgba(198, 69, 62, 0.45);
        outline-offset: 2px;
      }

      .sx-more { text-align: center; margin-top: 32px; }

      /* ---------- Responsive ---------- */
      @media (max-width: 900px) {
        .sx-page { padding: 18px 16px 44px; }
        .sx-hero { padding: 18px; gap: 20px; }
        .sx-hero-image { flex-basis: 200px; }
        .sx-hero h1 { font-size: 1.7rem; }
        .sx-grid { gap: 18px; }
      }

      @media (max-width: 640px) {
        .sx-page { padding: 14px 14px 36px; }
        .sx-breadcrumb { font-size: 0.85rem; margin-bottom: 12px; }
        .sx-hero { flex-direction: column; align-items: stretch; gap: 14px; padding: 14px; }
        .sx-hero-image { flex: none; width: 100%; }
        .sx-hero-image img { height: 130px; }
        .sx-hero h1 { font-size: 1.4rem; }
        .sx-hero p { font-size: 0.92rem; }
        .sx-tabs { justify-content: flex-start; margin-bottom: 16px; }
        .sx-tab { padding: 9px 16px; font-size: 0.9rem; }

        /* two cards per row on phones so the page isn't an endless scroll */
        .sx-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .sx-body-card { padding: 12px; gap: 8px; }
        .sx-name { font-size: 0.95rem; }
        .sx-price { font-size: 1.05rem; }
        .sx-row { flex-direction: column; align-items: stretch; gap: 8px; }
        .sx-btns { width: 100%; }
        .sx-btns .sx-btn { flex: 1; padding: 9px 8px; font-size: 0.82rem; }
      }

      @media (max-width: 360px) {
        .sx-grid { grid-template-columns: 1fr; }
      }

      @media (hover: none) {
        .sx-card:hover, .sx-btn:hover { transform: none; }
      }

      @media (prefers-reduced-motion: reduce) {
        * { transition: none !important; animation: none !important; }
      }
   </style>
</head>
<body class="sx-body">

<?php include 'header.php'; ?>

<main class="sx-page" role="main" aria-labelledby="menuTitle">
  <nav class="sx-breadcrumb" aria-label="Breadcrumb">
    <a href="index.php">Home</a><span>/</span>
    <a href="Item.php">Shop</a><span>/</span>
    <strong>Coffee</strong>
  </nav>

  <section class="sx-hero">
    <div class="sx-hero-image" aria-hidden="true">
      <img src="images/about.png" alt="">
    </div>
    <div>
      <h1 id="menuTitle">Coffee</h1>
      <p>Our curated coffee collection. Tap any product to see details or add it to your cart.</p>
    </div>
  </section>

  <div class="sx-tabs" role="toolbar" aria-label="Category navigation">
    <a href="Menu1P.php" class="sx-tab active" aria-current="page"><i class="fa-solid fa-mug-saucer"></i> Coffee</a>
    <a href="Menu2P.php" class="sx-tab"><i class="fa-solid fa-glass-water"></i> Drinks</a>
    <a href="Menu3P.php" class="sx-tab"><i class="fa-solid fa-cookie"></i> Sweets</a>
  </div>

  <section aria-label="Product list">
    <p class="sx-count"><?php echo count($products); ?> items</p>

    <div class="sx-grid" role="list">
      <?php foreach ($products as $i => $p): ?>
        <article class="sx-card" role="listitem" aria-labelledby="p<?php echo $i; ?>">
          <a href="<?php echo htmlspecialchars($p['link']); ?>" class="sx-img" aria-label="View <?php echo htmlspecialchars($p['name']); ?>">
            <img src="images/<?php echo htmlspecialchars($p['image']); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>" loading="lazy">
          </a>
          <div class="sx-body-card">
            <h2 id="p<?php echo $i; ?>" class="sx-name"><?php echo htmlspecialchars($p['name']); ?></h2>
            <div class="sx-row">
              <div class="sx-price">₱<?php echo number_format($p['price'], 2); ?></div>
              <div class="sx-btns">
                <a href="<?php echo htmlspecialchars($p['link']); ?>" class="sx-btn sx-btn-outline"><i class="fa-solid fa-eye"></i> View</a>
                <a href="<?php echo htmlspecialchars($p['link']); ?>" class="sx-btn sx-btn-primary"><i class="fa-solid fa-cart-plus"></i> Buy</a>
              </div>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="sx-more">
      <a href="Item.php" class="sx-tab"><i class="fa-solid fa-arrow-down"></i> Load latest products</a>
    </div>
  </section>
</main>

<?php include 'footer.php'; ?>

<script src="Js/script1.js"></script>
</body>
</html>