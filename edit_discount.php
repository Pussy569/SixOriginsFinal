<?php
include 'config.php';
session_start();

$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    header('location:login.php');
    exit;
}

if (!isset($_GET['id'])) {
    header('location:admin_discount.php');
    exit;
}

$id = intval($_GET['id']);
$select = $conn->prepare("SELECT * FROM discounts WHERE id = ?");
$select->bind_param("i", $id);
$select->execute();
$result = $select->get_result();
$discount = $result->fetch_assoc();
$select->close();

if (!$discount) {
    echo "Discount not found.";
    exit;
}

if (isset($_POST['update_discount'])) {
    $code = mysqli_real_escape_string($conn, $_POST['code']);
    $amount = intval($_POST['amount']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    $valid_until = $_POST['valid_until'];

    $update = $conn->prepare("UPDATE discounts SET code=?, amount=?, status=?, valid_until=? WHERE id=?");
    $update->bind_param("sissi", $code, $amount, $status, $valid_until, $id);
    $update->execute();
    $update->close();

    header("Location: admin_discount.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8" />
   <meta name="viewport" content="width=device-width,initial-scale=1" />
   <title>Edit Discount — Admin</title>
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Inter:wght@400;700&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
   <style>
      :root{
         /* Typica palette (keeps admin look aligned with index.php) */
         --coffee-main: #594838;
         --coffee-dark: #472f1b;
         --coffee-light: #85664c;
         --cream-bg: #f5eee6;
         --cream-panel: #fbf8f4;
         --cream-border: #ece4d9;
         --cream-hover: #f1ede6;
         --green-accent: #61ad5a;
         --gray-muted: #b3a89a;
         --radius: 12px;
         --shadow: 0 12px 28px rgba(60,48,40,0.06);
         --max-width: 980px;
      }

      *{box-sizing:border-box;margin:0;padding:0;font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial;}
      html,body{height:100%}
      body{
        min-height:100vh;
        background: linear-gradient(180deg,var(--cream-panel),var(--cream-bg));
        color:var(--coffee-dark);
        -webkit-font-smoothing:antialiased;
        -moz-osx-font-smoothing:grayscale;
        line-height:1.6;
      }

      .page { max-width:var(--max-width); margin:28px auto; padding:0 20px 60px; }

      .breadcrumb { font-size:0.95rem; color:var(--coffee-light); margin-bottom:14px; }

      .hero {
        display:flex;
        gap:18px;
        align-items:center;
        background: linear-gradient(90deg, rgba(245,238,230,0.6), rgba(251,248,244,0.4));
        padding:16px;
        border-radius:var(--radius);
        box-shadow:var(--shadow);
        margin-bottom:18px;
      }
      .hero .hero-image{flex:0 0 160px}
      .hero .hero-image img{width:100%;height:100px;object-fit:cover;border-radius:8px;border:1px solid var(--cream-border);display:block}
      .hero h1{font-size:1.25rem;margin-bottom:6px;color:var(--coffee-main);font-family:'Playfair Display',serif;}
      .hero p{color:var(--coffee-dark);margin:0;font-size:0.95rem}

      .card{
        background:var(--cream-panel);
        border-radius:12px;
        padding:18px;
        box-shadow:0 12px 30px var(--shadow);
        border:1.5px solid var(--cream-border);
      }

      .edit-container{
        max-width:760px;
        margin:0 auto;
      }

      h2.title {
        margin:0 0 12px 0;
        font-size:1.6rem;
        color:var(--coffee-main);
        font-family:'Playfair Display',serif;
      }

      form.field-grid{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:12px;
      }

      label{ display:block; font-weight:700; margin-bottom:6px; color:var(--coffee-dark); font-size:0.95rem; }

      input[type="text"],
      input[type="number"],
      input[type="date"],
      select {
        width:100%;
        padding:12px 14px;
        border-radius:10px;
        border:1.2px solid var(--cream-border);
        background:var(--cream-bg);
        font-size:1rem;
        color:var(--coffee-dark);
      }

      .full-width { grid-column: 1 / -1; }

      .btn-row{
        display:flex;
        gap:12px;
        margin-top:12px;
        justify-content:flex-end;
      }

      .btn {
        padding:10px 14px;
        border-radius:10px;
        border:0;
        cursor:pointer;
        font-weight:800;
        display:inline-flex;
        align-items:center;
        gap:8px;
      }
      .btn-primary{ background:linear-gradient(90deg,var(--green-accent), #479d4a); color:#fff; }
      .btn-ghost{ background:var(--cream-panel); border:1.2px solid var(--cream-border); color:var(--coffee-dark); font-weight:700; }

      .helper { font-size:0.9rem; color:var(--coffee-light); margin-top:6px; }

      @media (max-width:700px){
        .page{padding:0 12px}
        form.field-grid{grid-template-columns:1fr}
      }
   </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<main class="page" role="main" aria-labelledby="editDiscountTitle">
  <nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="admin_page.php" style="color:var(--green-accent);text-decoration:none;font-weight:700">Dashboard</a> &nbsp;/&nbsp; <a href="admin_discount.php" style="color:var(--green-accent);text-decoration:none;font-weight:700">Discounts</a> &nbsp;/&nbsp; <strong>Edit</strong>
  </nav>

  <section class="hero" aria-hidden="false">
    <div class="hero-image" aria-hidden="true">
      <img src="images/about.png" alt="Edit discount">
    </div>
    <div class="hero-content">
      <h1 id="editDiscountTitle">Edit Discount</h1>
      <p>Update the discount code, amount, status and expiration date. Changes are saved immediately.</p>
    </div>
  </section>

  <section class="edit-container card" aria-labelledby="formTitle">
    <h2 id="formTitle" class="title">Edit Discount</h2>

    <form method="POST" class="field-grid" novalidate>
       <div>
         <label for="code">Code</label>
         <input type="text" id="code" name="code" value="<?php echo htmlspecialchars($discount['code']); ?>" required>
         <div class="helper">Use an easy-to-share short code (no spaces), e.g. SAVE10</div>
       </div>

       <div>
         <label for="amount">Amount (%)</label>
         <input type="number" id="amount" name="amount" value="<?php echo intval($discount['amount']); ?>" required min="1" max="100">
         <div class="helper">Enter a whole percent between 1 and 100.</div>
       </div>

       <div class="full-width">
         <label for="status">Status</label>
         <select name="status" id="status" required>
            <option value="active" <?php if($discount['status'] == 'active') echo 'selected'; ?>>Active</option>
            <option value="expired" <?php if($discount['status'] == 'expired') echo 'selected'; ?>>Expired</option>
         </select>
       </div>

       <div class="full-width">
         <label for="valid_until">Valid Until</label>
         <input type="date" id="valid_until" name="valid_until" value="<?php echo htmlspecialchars($discount['valid_until']); ?>" required>
         <div class="helper">The discount will be usable up to and including this date.</div>
       </div>

       <div class="btn-row full-width">
         <a href="admin_discount.php" class="btn btn-ghost" style="text-decoration:none"><i class="fa-solid fa-arrow-left"></i> Back</a>
         <button type="submit" name="update_discount" class="btn btn-primary"><i class="fa-solid fa-save"></i> Update Discount</button>
       </div>
    </form>
  </section>
</main>


<script src="Js/script1.js"></script>
</body>
</html>