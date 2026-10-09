<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/services/admin_log_activity.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}
if (!is_head_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

$admin_filter = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT);
$admin_filter = $admin_filter !== false && $admin_filter !== null && $admin_filter > 0 ? $admin_filter : 0;
$action_filter = trim((string)($_GET['action'] ?? ''));
$action_filter = substr($action_filter, 0, 255);
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to = trim((string)($_GET['date_to'] ?? ''));

foreach (['date_from', 'date_to'] as $date_key) {
    if ($$date_key !== '') {
        $date_value = DateTimeImmutable::createFromFormat('!Y-m-d', $$date_key);
        if (!$date_value || $date_value->format('Y-m-d') !== $$date_key) {
            $$date_key = '';
        }
    }
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $page !== false && $page !== null && $page > 0 ? $page : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;
$filter_sql = "(? = 0 OR aa.admin_id = ?) AND (? = '' OR aa.action = ?) AND (? = '' OR aa.timestamp >= ?) AND (? = '' OR aa.timestamp < DATE_ADD(?, INTERVAL 1 DAY))";

$count_stmt = $conn->prepare("SELECT COUNT(*) FROM admin_activity aa WHERE $filter_sql");
$count_stmt->bind_param('iissssss', $admin_filter, $admin_filter, $action_filter, $action_filter, $date_from, $date_from, $date_to, $date_to);
$count_stmt->execute();
$count_stmt->bind_result($total_rows);
$count_stmt->fetch();
$count_stmt->close();
$total_rows = (int)$total_rows;
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$activity_stmt = $conn->prepare(
    "SELECT aa.id, aa.admin_id, aa.action, aa.description, aa.timestamp, aa.ip_address,
            aa.result, aa.record_type, aa.record_id, aa.before_values, aa.after_values,
            COALESCE(NULLIF(aa.admin_name_snapshot, ''), u.name, CONCAT('Admin #', aa.admin_id)) AS actor_name,
            COALESCE(NULLIF(aa.admin_email_snapshot, ''), u.email, '') AS actor_email
     FROM admin_activity aa
     LEFT JOIN users u ON u.id = aa.admin_id
     WHERE $filter_sql
     ORDER BY aa.timestamp DESC, aa.id DESC
     LIMIT ? OFFSET ?"
);
$activity_stmt->bind_param(
    'iissssssii',
    $admin_filter,
    $admin_filter,
    $action_filter,
    $action_filter,
    $date_from,
    $date_from,
    $date_to,
    $date_to,
    $per_page,
    $offset
);
$activity_stmt->execute();
$activity_rows = $activity_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$activity_stmt->close();

$admin_options = $conn->query(
    "SELECT DISTINCT aa.admin_id,
            COALESCE(NULLIF(aa.admin_name_snapshot, ''), u.name, CONCAT('Admin #', aa.admin_id)) AS actor_name
     FROM admin_activity aa
     LEFT JOIN users u ON u.id = aa.admin_id
     WHERE aa.admin_id > 0
     ORDER BY actor_name"
)->fetch_all(MYSQLI_ASSOC);
$action_result = $conn->query('SELECT DISTINCT action FROM admin_activity ORDER BY action');
$action_options = $action_result->fetch_all(MYSQLI_NUM);

function formatActivityValues(?string $values): string
{
    if ($values === null || $values === '') {
        return '';
    }
    $decoded = json_decode($values, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($formatted)) {
            return $formatted;
        }
    }
    return $values;
}

$filter_params = [
    'admin_id' => $admin_filter,
    'action' => $action_filter,
    'date_from' => $date_from,
    'date_to' => $date_to,
];
$filter_params = array_filter($filter_params, static fn($value) => $value !== '' && $value !== 0);
$page_url = static fn(int $target_page): string => 'admin_activity.php?' . http_build_query($filter_params + ['page' => $target_page]);
$current_page = 'admin_activity.php';
require __DIR__ . '/app/views/admin_header.php';
?>
<main class="activity-page">
    <section class="activity-heading">
        <div>
            <p class="eyebrow">HEAD ADMIN ONLY</p>
            <h1>Overseeing Admin Activity</h1>
            <p>Review administrative actions, outcomes, affected records, and recorded changes.</p>
        </div>
        <div class="activity-total"><strong><?php echo number_format($total_rows); ?></strong><span>audit records</span></div>
    </section>

    <form class="activity-filters" method="get" action="admin_activity.php">
        <label>Admin
            <select name="admin_id">
                <option value="">All admins</option>
                <?php foreach ($admin_options as $option): ?>
                    <option value="<?php echo (int)$option['admin_id']; ?>" <?php echo $admin_filter === (int)$option['admin_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($option['actor_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Action
            <select name="action">
                <option value="">All actions</option>
                <?php foreach ($action_options as $option_row): $option = $option_row[0]; ?>
                    <option value="<?php echo htmlspecialchars($option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" <?php echo $action_filter === $option ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>From
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>To
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <button type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        <a class="clear-filter" href="admin_activity.php">Clear</a>
    </form>

    <div class="activity-table-wrap">
        <table class="activity-table">
            <thead>
                <tr>
                    <th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Result</th><th>Details / Changes</th><th>IP</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$activity_rows): ?>
                <tr><td colspan="7" class="empty-state">No activity records match these filters.</td></tr>
            <?php else: ?>
                <?php foreach ($activity_rows as $activity): ?>
                    <tr>
                        <td class="date-cell"><?php echo htmlspecialchars($activity['timestamp'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($activity['actor_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                            <?php if ($activity['actor_email'] !== ''): ?><small><?php echo htmlspecialchars($activity['actor_email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($activity['action'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                        <td><?php
                            $record_label = trim((string)$activity['record_type'] . ($activity['record_id'] !== null ? ' #' . $activity['record_id'] : ''));
                            echo htmlspecialchars($record_label !== '' ? $record_label : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        ?></td>
                        <td><span class="result-pill <?php echo $activity['result'] === 'failure' ? 'failed' : 'succeeded'; ?>"><?php echo htmlspecialchars(ucfirst($activity['result']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td class="details-cell">
                            <?php if ($activity['description'] !== ''): ?><div><?php echo nl2br(htmlspecialchars($activity['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></div><?php endif; ?>
                            <?php foreach (['Before' => $activity['before_values'], 'After' => $activity['after_values']] as $label => $values): ?>
                                <?php if ($values !== null && $values !== ''): ?>
                                    <details><summary><?php echo $label; ?> values</summary><pre><?php echo htmlspecialchars(formatActivityValues($values), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre></details>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                        <td><?php echo htmlspecialchars((string)($activity['ip_address'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
        <nav class="activity-pagination" aria-label="Audit log pages">
            <?php if ($page > 1): ?><a href="<?php echo htmlspecialchars($page_url($page - 1), ENT_QUOTES, 'UTF-8'); ?>">Previous</a><?php endif; ?>
            <span>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
            <?php if ($page < $total_pages): ?><a href="<?php echo htmlspecialchars($page_url($page + 1), ENT_QUOTES, 'UTF-8'); ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</main>
<style>
.activity-page{max-width:1500px;margin:32px auto;padding:0 24px 48px;color:#5E1F13}.activity-heading{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:24px}.activity-heading h1{font-size:clamp(1.6rem,3vw,2.4rem);margin:4px 0 8px}.activity-heading p{color:#664C47}.activity-heading .eyebrow{font-size:.75rem;font-weight:800;letter-spacing:.12em;color:#C6453E}.activity-total{display:grid;text-align:center;background:#fff;border:1px solid #eadfd4;border-radius:14px;padding:14px 20px;min-width:130px}.activity-total strong{font-size:1.5rem}.activity-total span{font-size:.8rem;color:#664C47}.activity-filters{display:flex;align-items:end;flex-wrap:wrap;gap:14px;background:#fff;padding:18px;border:1px solid #eadfd4;border-radius:14px;margin-bottom:20px}.activity-filters label{display:grid;gap:6px;font-size:.8rem;font-weight:700}.activity-filters select,.activity-filters input{min-height:40px;padding:8px 10px;border:1px solid #dacfc5;border-radius:8px;background:#fff;color:#5E1F13}.activity-filters button,.activity-filters .clear-filter,.activity-pagination a{border:0;border-radius:8px;padding:10px 14px;background:#5E1F13;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.activity-filters .clear-filter{background:#f2e9e0;color:#5E1F13}.activity-table-wrap{overflow:auto;background:#fff;border:1px solid #eadfd4;border-radius:14px}.activity-table{width:100%;border-collapse:collapse;min-width:1080px}.activity-table th,.activity-table td{text-align:left;padding:13px 12px;border-bottom:1px solid #f0e8e0;vertical-align:top}.activity-table th{background:#fff8f0;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em}.activity-table td{font-size:.84rem}.activity-table td small{display:block;margin-top:4px;color:#76675f}.date-cell{white-space:nowrap}.result-pill{display:inline-block;border-radius:999px;padding:5px 9px;font-size:.75rem;font-weight:800}.result-pill.succeeded{background:#e8f6ec;color:#18713a}.result-pill.failed{background:#fde9e6;color:#a62f25}.details-cell{min-width:230px;max-width:420px;overflow-wrap:anywhere}.details-cell details{margin-top:7px}.details-cell summary{font-weight:700;cursor:pointer}.details-cell pre{white-space:pre-wrap;background:#faf7f3;padding:8px;border-radius:6px;font-size:.75rem}.empty-state{text-align:center!important;padding:40px!important;color:#76675f}.activity-pagination{display:flex;justify-content:center;align-items:center;gap:16px;margin-top:20px}.activity-pagination span{font-weight:700}@media(max-width:650px){.activity-page{padding:0 12px 32px}.activity-heading{align-items:flex-start;flex-direction:column}.activity-filters>*{flex:1 1 140px}.activity-filters button,.activity-filters .clear-filter{text-align:center}}
</style>
<?php require __DIR__ . '/app/views/admin_footer.php'; ?>
