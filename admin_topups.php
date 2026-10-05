<?php
include 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// CSRF token (actions now use POST instead of GET links)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['request_id'] ?? 0);

    if (!hash_equals($_SESSION['csrf_token'], $token) || $id <= 0) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'error';
    } elseif ($action === 'approve') {
        mysqli_begin_transaction($conn);
        try {
            // Only pending requests can be approved (prevents double-crediting)
            $stmt = mysqli_prepare($conn, "SELECT user_id, amount FROM topup_requests WHERE id = ? AND status = 'pending' FOR UPDATE");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

            if (!$request) {
                throw new Exception('Request not found or already processed.');
            }

            $stmt = mysqli_prepare($conn, "UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'di', $request['amount'], $request['user_id']);
            mysqli_stmt_execute($stmt);

            $stmt = mysqli_prepare($conn, "UPDATE topup_requests SET status = 'approved' WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);

            mysqli_commit($conn);
            $_SESSION['message'] = 'Top-up approved successfully!';
            $_SESSION['message_type'] = 'success';
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $_SESSION['message'] = $e->getMessage();
            $_SESSION['message_type'] = 'error';
        }
    } elseif ($action === 'reject') {
        $stmt = mysqli_prepare($conn, "UPDATE topup_requests SET status = 'rejected' WHERE id = ? AND status = 'pending'");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $_SESSION['message'] = 'Top-up request rejected.';
        $_SESSION['message_type'] = 'error';
    }

    header('Location: admin_topups.php');
    exit();
}

// Load pending requests
$requests = [];
$total_amount = 0;
$result = mysqli_query($conn, "SELECT t.*, u.name FROM topup_requests t JOIN users u ON t.user_id = u.id WHERE t.status = 'pending' ORDER BY t.id DESC");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $requests[] = $row;
        $total_amount += (float)$row['amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Wallet Top-ups — Six Origins Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
<link rel="icon" type="image/png" href="images/logos.png">
<style>
:root {
  --primary-red: #C6453E;
  --dark-brown: #5E1F13;
  --gray-brown: #664C47;
  --light-cream: #FFF2E0;
  --white: #FFFFFF;
  --radius: 16px;
  --shadow: 0 8px 24px rgba(94, 31, 19, 0.08);
  --shadow-hover: 0 12px 36px rgba(94, 31, 19, 0.12);
  --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
* { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial; }
body { min-height: 100vh; display: flex; flex-direction: column; background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%); color: var(--dark-brown); }
.page { flex: 1; width: 100%; max-width: 1100px; margin: 32px auto 0; padding: 0 18px 40px; }

/* Header */
.header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
.header h1 { font-size: 1.9rem; font-weight: 900; letter-spacing: -0.5px; }
.header .subtitle { color: var(--gray-brown); margin-top: 4px; font-weight: 500; }
.back-btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 18px; background: linear-gradient(135deg, var(--primary-red), #B83A34); color: #fff; text-decoration: none; border-radius: var(--radius); font-weight: 800; box-shadow: 0 4px 12px rgba(198,69,62,.2); transition: var(--transition); }
.back-btn:hover { background: linear-gradient(135deg, var(--dark-brown), #3D1608); transform: translateY(-2px); }

/* Stats */
.stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-bottom: 20px; }
.stat { display: flex; align-items: center; gap: 14px; background: #FFFBF7; border: 1.5px solid #F0E6D8; border-radius: var(--radius); padding: 16px 18px; box-shadow: var(--shadow); }
.stat-icon { width: 46px; height: 46px; border-radius: 12px; background: rgba(198,69,62,.1); color: var(--primary-red); display: grid; place-items: center; font-size: 1.2rem; flex-shrink: 0; }
.stat-label { font-size: .8rem; font-weight: 700; color: var(--gray-brown); text-transform: uppercase; letter-spacing: .5px; }
.stat-value { font-size: 1.5rem; font-weight: 900; }

/* Toast message */
.message { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; padding: 14px 18px; border-left: 5px solid var(--primary-red); border-radius: var(--radius); background: rgba(198,69,62,.08); color: var(--primary-red); font-weight: 700; animation: fadeIn .3s ease; }
.message.error { background: rgba(217,126,106,.1); border-left-color: #D97E6A; color: #8B4A42; }
.message .close { margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 1rem; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: none; } }

/* Toolbar */
.toolbar { display: flex; gap: 12px; margin-bottom: 16px; }
.search { position: relative; flex: 1; }
.search i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--gray-brown); }
.search input { width: 100%; padding: 12px 14px 12px 40px; border: 1.5px solid #F0E6D8; border-radius: 12px; background: #FFFBF7; color: var(--dark-brown); font-weight: 600; font-size: .95rem; outline: none; transition: var(--transition); }
.search input:focus { border-color: var(--primary-red); box-shadow: 0 0 0 3px rgba(198,69,62,.12); }
.sort { padding: 12px 14px; border: 1.5px solid #F0E6D8; border-radius: 12px; background: #FFFBF7; color: var(--dark-brown); font-weight: 700; cursor: pointer; outline: none; }
.sort:focus { border-color: var(--primary-red); }

/* Cards */
.requests { display: grid; gap: 14px; }
.card { display: grid; grid-template-columns: 120px 1fr auto; gap: 18px; align-items: center; padding: 16px; border-radius: 14px; background: linear-gradient(135deg, #FFFBF7, #FEFDFB); border: 1.5px solid #F0E6D8; box-shadow: var(--shadow); transition: var(--transition); }
.card:hover { box-shadow: var(--shadow-hover); border-color: var(--primary-red); }
.thumb { width: 120px; height: 120px; border-radius: 12px; overflow: hidden; border: 2px solid #F0E6D8; background: var(--light-cream); display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 6px; color: var(--gray-brown); font-size: .75rem; font-weight: 700; text-align: center; position: relative; padding: 0; }
.thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
button.thumb { cursor: zoom-in; }
button.thumb::after { content: '\f00e'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; inset: 0; display: grid; place-items: center; background: rgba(94,31,19,.5); color: #fff; font-size: 1.4rem; opacity: 0; transition: var(--transition); }
button.thumb:hover::after, button.thumb:focus-visible::after { opacity: 1; }
.thumb.empty i { font-size: 1.6rem; color: var(--primary-red); }

.info { display: grid; gap: 8px; min-width: 0; }
.name-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.name { font-size: 1.1rem; font-weight: 800; }
.tag { font-size: .75rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; background: rgba(102,76,71,.1); color: var(--gray-brown); }
.amount { display: inline-flex; width: fit-content; padding: 8px 14px; border-radius: 10px; background: linear-gradient(135deg, var(--primary-red), #B83A34); color: #fff; font-weight: 900; font-size: 1.15rem; box-shadow: 0 4px 12px rgba(198,69,62,.2); }
.meta { color: var(--gray-brown); font-size: .88rem; font-weight: 600; display: flex; gap: 6px; align-items: center; }
.meta i { color: var(--primary-red); }

.actions { display: flex; flex-direction: column; gap: 8px; min-width: 140px; }
.btn { padding: 11px 16px; border-radius: 10px; border: none; cursor: pointer; font-weight: 800; font-size: .92rem; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: var(--transition); }
.btn-approve { background: linear-gradient(135deg, var(--primary-red), #B83A34); color: #fff; box-shadow: 0 4px 12px rgba(198,69,62,.2); }
.btn-approve:hover { background: linear-gradient(135deg, var(--dark-brown), #3D1608); transform: translateY(-2px); }
.btn-reject { background: rgba(217,126,106,.1); color: #C25A52; border: 1.5px solid rgba(217,126,106,.3); }
.btn-reject:hover { background: linear-gradient(135deg, #D97E6A, #C25A52); color: #fff; transform: translateY(-2px); }
.btn-cancel { background: #F5EFE7; color: var(--gray-brown); }
.btn-cancel:hover { background: #EBDFD0; }
.btn:focus-visible, .thumb:focus-visible { outline: 3px solid rgba(198,69,62,.4); outline-offset: 2px; }

.empty-state { padding: 56px 24px; text-align: center; color: var(--gray-brown); font-weight: 700; background: #FFFBF7; border: 1.5px dashed #E5D5C0; border-radius: var(--radius); }
.empty-state i { font-size: 2.8rem; color: var(--primary-red); margin-bottom: 12px; display: block; }
#noMatch { display: none; }

/* Modals */
.modal { position: fixed; inset: 0; background: rgba(61,22,8,.6); display: none; align-items: center; justify-content: center; padding: 18px; z-index: 1000; }
.modal.open { display: flex; animation: fadeIn .2s ease; }
.dialog { background: #FFFBF7; border-radius: var(--radius); padding: 26px; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.dialog .icon { width: 56px; height: 56px; margin: 0 auto 14px; border-radius: 50%; display: grid; place-items: center; font-size: 1.5rem; background: rgba(198,69,62,.1); color: var(--primary-red); }
.dialog h3 { font-size: 1.2rem; font-weight: 900; margin-bottom: 8px; }
.dialog p { color: var(--gray-brown); font-weight: 500; line-height: 1.5; margin-bottom: 20px; }
.dialog .row { display: flex; gap: 10px; }
.dialog .row .btn { flex: 1; }
.lightbox { position: relative; max-width: 900px; width: 100%; }
.lightbox img { width: 100%; max-height: 85vh; object-fit: contain; border-radius: 12px; background: #fff; display: block; }
.lightbox .x { position: absolute; top: -14px; right: -14px; width: 38px; height: 38px; border-radius: 50%; border: none; background: var(--primary-red); color: #fff; cursor: pointer; font-size: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,.3); }

/* Responsive */
@media (max-width: 760px) {
  .header h1 { font-size: 1.45rem; }
  .card { grid-template-columns: 96px 1fr; }
  .thumb { width: 96px; height: 96px; }
  .actions { grid-column: 1 / -1; flex-direction: row; }
  .actions .btn { flex: 1; }
}
@media (max-width: 520px) {
  .stats { grid-template-columns: 1fr; }
  .toolbar { flex-direction: column; }
  .stat-value { font-size: 1.3rem; }
}
</style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<div class="page">
  <div class="header">
    <div>
      <h1>☕ Wallet Top-ups</h1>
      <div class="subtitle">Review and approve or reject customer wallet top-up requests</div>
    </div>
    <a href="admin_page.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
  </div>

  <?php if (isset($_SESSION['message'])):
      $is_error = ($_SESSION['message_type'] ?? '') === 'error'; ?>
    <div class="message flash-message <?php echo $is_error ? 'error' : ''; ?>" role="status">
      <i class="fa-solid <?php echo $is_error ? 'fa-circle-xmark' : 'fa-circle-check'; ?>"></i>
      <span><?php echo htmlspecialchars($_SESSION['message']); ?></span>
      <button class="close" type="button" aria-label="Dismiss" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
  <?php endif; ?>

  <div class="stats">
    <div class="stat">
      <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
      <div><div class="stat-label">Pending requests</div><div class="stat-value" id="statCount"><?php echo count($requests); ?></div></div>
    </div>
    <div class="stat">
      <div class="stat-icon"><i class="fa-solid fa-peso-sign"></i></div>
      <div><div class="stat-label">Total pending amount</div><div class="stat-value">₱<?php echo number_format($total_amount, 2); ?></div></div>
    </div>
  </div>

  <?php if (empty($requests)): ?>
    <div class="empty-state"><i class="fa-solid fa-inbox"></i>No pending top-up requests</div>
  <?php else: ?>
    <div class="toolbar">
      <label class="search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="search" id="search" placeholder="Search by name, user ID or amount…" autocomplete="off">
      </label>
      <select class="sort" id="sort" aria-label="Sort requests">
        <option value="newest">Newest first</option>
        <option value="oldest">Oldest first</option>
        <option value="high">Highest amount</option>
        <option value="low">Lowest amount</option>
      </select>
    </div>

    <div class="requests" id="list">
      <?php foreach ($requests as $row):
          $has_image = !empty($row['screenshot']);
          $proof_url = $has_image ? 'topup_proof.php?id=' . (int)$row['id'] : null;
          $amount_fmt = number_format((float)$row['amount'], 2);
      ?>
        <div class="card"
             data-id="<?php echo (int)$row['id']; ?>"
             data-amount="<?php echo (float)$row['amount']; ?>"
             data-search="<?php echo htmlspecialchars(strtolower($row['name'] . ' ' . $row['user_id'] . ' ' . $row['amount'])); ?>">

          <?php if ($has_image): ?>
            <button type="button" class="thumb" data-full="<?php echo htmlspecialchars($proof_url, ENT_QUOTES, 'UTF-8'); ?>" aria-label="View payment proof full size">
              <img src="<?php echo htmlspecialchars($proof_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Payment proof for request #<?php echo (int)$row['id']; ?>" loading="lazy">
            </button>
          <?php else: ?>
            <div class="thumb empty"><i class="fa-solid fa-image"></i><span>No proof uploaded</span></div>
          <?php endif; ?>

          <div class="info">
            <div class="name-row">
              <span class="name"><?php echo htmlspecialchars($row['name']); ?></span>
              <span class="tag">User #<?php echo (int)$row['user_id']; ?></span>
              <span class="tag">Req #<?php echo (int)$row['id']; ?></span>
            </div>
            <span class="amount">₱<?php echo $amount_fmt; ?></span>
            <span class="meta"><i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($row['created_at'] ?? 'Unknown'); ?></span>
          </div>

          <div class="actions">
            <button type="button" class="btn btn-approve"
                    data-action="approve" data-id="<?php echo (int)$row['id']; ?>"
                    data-name="<?php echo htmlspecialchars($row['name']); ?>" data-amount="₱<?php echo $amount_fmt; ?>">
              <i class="fa-solid fa-check-circle"></i> Approve
            </button>
            <button type="button" class="btn btn-reject"
                    data-action="reject" data-id="<?php echo (int)$row['id']; ?>"
                    data-name="<?php echo htmlspecialchars($row['name']); ?>" data-amount="₱<?php echo $amount_fmt; ?>">
              <i class="fa-solid fa-times-circle"></i> Reject
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="empty-state" id="noMatch"><i class="fa-solid fa-magnifying-glass"></i>No requests match your search</div>
  <?php endif; ?>
</div>

<!-- Hidden form used by the confirmation dialog -->
<form method="post" id="actionForm" hidden>
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action" id="fAction">
  <input type="hidden" name="request_id" id="fId">
</form>

<!-- Confirm dialog -->
<div class="modal" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="cTitle">
  <div class="dialog">
    <div class="icon" id="cIcon"><i class="fa-solid fa-check"></i></div>
    <h3 id="cTitle"></h3>
    <p id="cText"></p>
    <div class="row">
      <button type="button" class="btn btn-cancel" id="cCancel">Cancel</button>
      <button type="button" class="btn btn-approve" id="cOk">Confirm</button>
    </div>
  </div>
</div>

<!-- Image lightbox -->
<div class="modal" id="imgModal" role="dialog" aria-modal="true" aria-label="Payment proof">
  <div class="lightbox">
    <button type="button" class="x" id="imgClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <img id="imgFull" src="" alt="Payment proof">
  </div>
</div>

<script>
(function () {
  const $ = (s) => document.querySelector(s);
  const list = $('#list');
  const confirmModal = $('#confirmModal');
  const imgModal = $('#imgModal');
  let pending = null;

  // Confirm dialog
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-action]');
    if (btn) {
      pending = { action: btn.dataset.action, id: btn.dataset.id };
      const approve = pending.action === 'approve';
      $('#cTitle').textContent = approve ? 'Approve this top-up?' : 'Reject this request?';
      $('#cText').textContent = approve
        ? btn.dataset.amount + ' will be added to ' + btn.dataset.name + "'s wallet."
        : "The request from " + btn.dataset.name + ' (' + btn.dataset.amount + ') will be rejected.';
      $('#cIcon').innerHTML = '<i class="fa-solid ' + (approve ? 'fa-check' : 'fa-xmark') + '"></i>';
      const ok = $('#cOk');
      ok.className = 'btn ' + (approve ? 'btn-approve' : 'btn-reject');
      ok.innerHTML = approve ? '<i class="fa-solid fa-check-circle"></i> Approve' : '<i class="fa-solid fa-times-circle"></i> Reject';
      confirmModal.classList.add('open');
      ok.focus();
      return;
    }
    const thumb = e.target.closest('.thumb[data-full]');
    if (thumb) {
      $('#imgFull').src = thumb.dataset.full;
      imgModal.classList.add('open');
    }
  });

  $('#cCancel').onclick = () => confirmModal.classList.remove('open');
  $('#cOk').onclick = function () {
    if (!pending) return;
    this.disabled = true; // prevent double submit
    $('#fAction').value = pending.action;
    $('#fId').value = pending.id;
    $('#actionForm').submit();
  };
  $('#imgClose').onclick = () => imgModal.classList.remove('open');

  [confirmModal, imgModal].forEach(m => m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); }));
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') [confirmModal, imgModal].forEach(m => m.classList.remove('open'));
  });

  if (!list) return;

  // Search + sort
  const cards = Array.from(list.children);
  const search = $('#search');
  const sort = $('#sort');

  function render() {
    const q = search.value.trim().toLowerCase();
    let visible = 0;
    cards.forEach(c => {
      const show = !q || c.dataset.search.includes(q);
      c.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    $('#noMatch').style.display = visible ? 'none' : 'block';

    const by = {
      newest: (a, b) => b.dataset.id - a.dataset.id,
      oldest: (a, b) => a.dataset.id - b.dataset.id,
      high:   (a, b) => b.dataset.amount - a.dataset.amount,
      low:    (a, b) => a.dataset.amount - b.dataset.amount
    }[sort.value];
    cards.slice().sort(by).forEach(c => list.appendChild(c));
  }
  search.addEventListener('input', render);
  sort.addEventListener('change', render);

  // Press "/" to focus search
  document.addEventListener('keydown', e => {
    if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
      e.preventDefault();
      search.focus();
    }
  });
})();
</script>
<?php include 'admin_footer.php'; ?>
</body>
</html>