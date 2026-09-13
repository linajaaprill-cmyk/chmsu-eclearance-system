<?php
// ============================================================
// CONSTRUCTION PROJECT EXPENSE RECORDING SYSTEM
// Single File PHP Application
// ============================================================

// --- DATABASE CONFIGURATION ---
$host = 'localhost';
$dbname = 'construction_expense_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// --- SESSION & INIT ---
session_start();

// --- SIMPLE AUTH (demo) ---
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1; // auto-login for demo
    $_SESSION['company_name'] = 'My Construction Co.';
}

// --- HELPER FUNCTIONS ---
function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}

function getProjectOptions($pdo) {
    $stmt = $pdo->query("SELECT id, project_name FROM projects ORDER BY project_name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCategoryOptions($pdo) {
    $stmt = $pdo->query("SELECT id, category_name FROM expense_categories ORDER BY category_name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- HANDLE POST REQUESTS (CRUD) ---

// 1. Add / Edit Project
if (isset($_POST['save_project'])) {
    $id = $_POST['project_id'] ?? null;
    $name = $_POST['project_name'];
    $client = $_POST['client_name'];
    $location = $_POST['location'];
    $budget = $_POST['budget'];
    $start = $_POST['start_date'];
    $end = $_POST['end_date'];
    $status = $_POST['status'];
    if ($id) {
        $stmt = $pdo->prepare("UPDATE projects SET project_name=?, client_name=?, location=?, budget=?, start_date=?, end_date=?, status=? WHERE id=?");
        $stmt->execute([$name, $client, $location, $budget, $start, $end, $status, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO projects (project_name, client_name, location, budget, start_date, end_date, status) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$name, $client, $location, $budget, $start, $end, $status]);
    }
    header("Location: ?page=projects");
    exit;
}

// 2. Delete Project
if (isset($_GET['delete_project'])) {
    $id = $_GET['delete_project'];
    $stmt = $pdo->prepare("DELETE FROM projects WHERE id=?");
    $stmt->execute([$id]);
    header("Location: ?page=projects");
    exit;
}

// 3. Add / Edit Expense
if (isset($_POST['save_expense'])) {
    $id = $_POST['expense_id'] ?? null;
    $project_id = $_POST['project_id'];
    $category_id = $_POST['category_id'];
    $date = $_POST['date'];
    $description = $_POST['description'];
    $quantity = $_POST['quantity'];
    $unit_cost = $_POST['unit_cost'];
    $total_cost = $quantity * $unit_cost;
    $supplier = $_POST['supplier'];
    $payment_method = $_POST['payment_method'];
    $receipt = $_POST['receipt_reference'];
    if ($id) {
        $stmt = $pdo->prepare("UPDATE expenses SET project_id=?, category_id=?, date=?, description=?, quantity=?, unit_cost=?, total_cost=?, supplier=?, payment_method=?, receipt_reference=? WHERE id=?");
        $stmt->execute([$project_id, $category_id, $date, $description, $quantity, $unit_cost, $total_cost, $supplier, $payment_method, $receipt, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO expenses (project_id, category_id, date, description, quantity, unit_cost, total_cost, supplier, payment_method, receipt_reference) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$project_id, $category_id, $date, $description, $quantity, $unit_cost, $total_cost, $supplier, $payment_method, $receipt]);
    }
    header("Location: ?page=expenses");
    exit;
}

// 4. Delete Expense
if (isset($_GET['delete_expense'])) {
    $id = $_GET['delete_expense'];
    $stmt = $pdo->prepare("DELETE FROM expenses WHERE id=?");
    $stmt->execute([$id]);
    header("Location: ?page=expenses");
    exit;
}

// 5. Add / Edit Category
if (isset($_POST['save_category'])) {
    $id = $_POST['category_id'] ?? null;
    $name = $_POST['category_name'];
    if ($id) {
        $stmt = $pdo->prepare("UPDATE expense_categories SET category_name=? WHERE id=?");
        $stmt->execute([$name, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO expense_categories (category_name) VALUES (?)");
        $stmt->execute([$name]);
    }
    header("Location: ?page=categories");
    exit;
}

// 6. Delete Category
if (isset($_GET['delete_category'])) {
    $id = $_GET['delete_category'];
    $stmt = $pdo->prepare("DELETE FROM expense_categories WHERE id=?");
    $stmt->execute([$id]);
    header("Location: ?page=categories");
    exit;
}

// --- GET CURRENT PAGE ---
$page = $_GET['page'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Construction Expense System</title>
    <style>
        /* ----- GLOBAL RESET & FONTS ----- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Times New Roman', Times, serif;
            font-size: 14px;
        }
        body {
            display: flex;
            background: #ffffff;
            min-height: 100vh;
            color: #000000;
        }
        /* ----- SIDEBAR (darkblue) ----- */
        .sidebar {
            width: 230px;
            background: #0a2a4a; /* dark blue */
            color: #ffffff;
            padding: 20px 0;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow-y: auto;
            flex-shrink: 0;
            border-radius: 0 20px 20px 0;
            box-shadow: 2px 0 10px rgba(0,0,0,0.2);
        }
        .sidebar h2 {
            color: #ffffff;
            text-align: center;
            font-size: 18px;
            padding: 0 10px 20px 10px;
            border-bottom: 1px solid #3a6a9a;
            letter-spacing: 1px;
            font-weight: normal;
        }
        .sidebar a {
            display: block;
            color: #e0e8f0;
            text-decoration: none;
            padding: 12px 20px;
            margin: 6px 12px;
            border-radius: 30px;
            transition: 0.2s;
            font-size: 14px;
            background: transparent;
        }
        .sidebar a:hover {
            background: #1e4a7a;
            color: #ffffff;
        }
        .sidebar a.active {
            background: #ffffff;
            color: #0a2a4a;
            font-weight: bold;
        }
        /* ----- MAIN CONTENT ----- */
        .main {
            flex: 1;
            padding: 25px 30px;
            background: #ffffff;
            min-height: 100vh;
        }
        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #0a2a4a;
            padding-bottom: 12px;
            margin-bottom: 25px;
        }
        .header-bar h1 {
            font-size: 24px;
            color: #0a2a4a;
            font-weight: normal;
            letter-spacing: 1px;
        }
        .header-bar .company {
            color: #0a2a4a;
            font-size: 16px;
        }
        /* ----- CARDS, TABLES, FORMS (rounded) ----- */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }
        .card {
            background: #f4f7fc;
            padding: 18px 15px;
            border-radius: 30px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            border: 1px solid #dce3ed;
            text-align: center;
        }
        .card h3 {
            font-size: 14px;
            color: #1a3a5a;
            font-weight: normal;
            margin-bottom: 6px;
        }
        .card .number {
            font-size: 28px;
            font-weight: bold;
            color: #0a2a4a;
        }
        .card .small {
            font-size: 13px;
            color: #2a4a6a;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            margin: 18px 0;
            border: 1px solid #dce3ed;
        }
        th {
            background: #0a2a4a;
            color: #ffffff;
            padding: 10px 10px;
            text-align: left;
            font-weight: normal;
            font-size: 13px;
        }
        td {
            padding: 10px 10px;
            border-bottom: 1px solid #e6ecf3;
            font-size: 13px;
        }
        tr:last-child td {
            border-bottom: none;
        }
        .btn {
            display: inline-block;
            background: #0a2a4a;
            color: #ffffff;
            padding: 8px 18px;
            border-radius: 40px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
            transition: 0.2s;
            margin: 4px 4px 0 0;
        }
        .btn:hover {
            background: #1e4a7a;
        }
        .btn-danger {
            background: #a03a3a;
        }
        .btn-danger:hover {
            background: #c04a4a;
        }
        .btn-sm {
            padding: 4px 14px;
            font-size: 12px;
        }
        .btn-outline {
            background: transparent;
            color: #0a2a4a;
            border: 1px solid #0a2a4a;
        }
        .btn-outline:hover {
            background: #0a2a4a;
            color: #fff;
        }
        .form-group {
            margin-bottom: 14px;
        }
        .form-group label {
            display: block;
            font-weight: bold;
            color: #0a2a4a;
            margin-bottom: 4px;
            font-size: 13px;
        }
        .form-control {
            width: 100%;
            padding: 8px 14px;
            border-radius: 30px;
            border: 1px solid #bcc9db;
            background: #fafcff;
            font-size: 13px;
        }
        .form-control:focus {
            outline: 2px solid #0a2a4a;
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin-bottom: 18px;
        }
        .filter-row select, .filter-row input {
            padding: 6px 14px;
            border-radius: 30px;
            border: 1px solid #bcc9db;
            background: #fafcff;
            font-size: 13px;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(10,42,74,0.4);
            justify-content: center;
            align-items: center;
            z-index: 999;
        }
        .modal:target {
            display: flex;
        }
        .modal-box {
            background: #ffffff;
            padding: 28px 32px;
            border-radius: 40px;
            max-width: 560px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }
        .modal-box h2 {
            color: #0a2a4a;
            margin-bottom: 18px;
            font-weight: normal;
            font-size: 20px;
        }
        .close-modal {
            float: right;
            background: #dce3ed;
            padding: 0 12px;
            border-radius: 30px;
            text-decoration: none;
            color: #0a2a4a;
            font-size: 20px;
        }
        .message {
            padding: 12px 18px;
            border-radius: 40px;
            background: #dff0d8;
            color: #2a5a2a;
            margin-bottom: 18px;
            border: 1px solid #b8d4b8;
        }
        .budget-exceeded {
            color: #b02a2a;
            font-weight: bold;
            background: #fce4e4;
            padding: 6px 14px;
            border-radius: 40px;
            display: inline-block;
        }
        /* responsive */
        @media (max-width: 768px) {
            .sidebar { width: 180px; padding: 12px 0; }
            .sidebar a { padding: 10px 12px; margin: 4px 8px; font-size: 13px; }
            .main { padding: 16px; }
        }
        /* small fonts override */
        .small-text { font-size: 12px; }
        .table-wrap { overflow-x: auto; }
    </style>
</head>
<body>
<!-- ============================================================ -->
<!-- SIDEBAR -->
<div class="sidebar">
    <h2>📋 EXPENSE</h2>
    <a href="?page=dashboard" class="<?= $page=='dashboard'?'active':'' ?>">📊 Dashboard</a>
    <a href="?page=projects" class="<?= $page=='projects'?'active':'' ?>">🏗️ Projects</a>
    <a href="?page=expenses" class="<?= $page=='expenses'?'active':'' ?>">💰 Expenses</a>
    <a href="?page=categories" class="<?= $page=='categories'?'active':'' ?>">📂 Categories</a>
    <a href="?page=reports" class="<?= $page=='reports'?'active':'' ?>">📄 Reports</a>
    <a href="?page=settings" class="<?= $page=='settings'?'active':'' ?>">⚙️ Settings</a>
</div>

<!-- ============================================================ -->
<!-- MAIN CONTENT -->
<div class="main">

    <!-- HEADER -->
    <div class="header-bar">
        <h1>RECORDING EXPENSES</h1>
        <span class="company"><?= htmlspecialchars($_SESSION['company_name'] ?? 'My Company') ?></span>
    </div>

    <!-- ========================================================== -->
    <!-- DASHBOARD -->
    <?php if ($page == 'dashboard'): ?>
        <?php
            // totals
            $totalProjects = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
            $totalExpenses = $pdo->query("SELECT SUM(total_cost) FROM expenses")->fetchColumn() ?: 0;
            $monthExpenses = $pdo->query("SELECT SUM(total_cost) FROM expenses WHERE MONTH(date)=MONTH(CURDATE()) AND YEAR(date)=YEAR(CURDATE())")->fetchColumn() ?: 0;
            $totalBudget = $pdo->query("SELECT SUM(budget) FROM projects")->fetchColumn() ?: 0;
            $remaining = $totalBudget - $totalExpenses;
            // recent expenses
            $recent = $pdo->query("SELECT e.*, p.project_name, c.category_name FROM expenses e JOIN projects p ON e.project_id=p.id JOIN expense_categories c ON e.category_id=c.id ORDER BY e.date DESC LIMIT 5")->fetchAll();
            // category totals
            $catTotals = $pdo->query("SELECT c.category_name, SUM(e.total_cost) as total FROM expenses e JOIN expense_categories c ON e.category_id=c.id GROUP BY c.id")->fetchAll();
            // project summary
            $projSummary = $pdo->query("SELECT p.project_name, SUM(e.total_cost) as spent, p.budget FROM projects p LEFT JOIN expenses e ON p.id=e.project_id GROUP BY p.id")->fetchAll();
        ?>
        <div class="card-grid">
            <div class="card"><h3>Total Projects</h3><div class="number"><?= $totalProjects ?></div></div>
            <div class="card"><h3>Total Expenses</h3><div class="number"><?= formatCurrency($totalExpenses) ?></div></div>
            <div class="card"><h3>This Month</h3><div class="number"><?= formatCurrency($monthExpenses) ?></div></div>
            <div class="card"><h3>Remaining Budget</h3><div class="number <?= $remaining < 0 ? 'budget-exceeded' : '' ?>"><?= formatCurrency($remaining) ?></div></div>
        </div>

        <h3 style="color:#0a2a4a; margin-top:20px;">Recent Expenses</h3>
        <div class="table-wrap">
        <table>
            <tr><th>Project</th><th>Category</th><th>Description</th><th>Total</th><th>Date</th></tr>
            <?php foreach($recent as $r): ?>
            <tr><td><?= htmlspecialchars($r['project_name']) ?></td><td><?= htmlspecialchars($r['category_name']) ?></td><td><?= htmlspecialchars($r['description']) ?></td><td><?= formatCurrency($r['total_cost']) ?></td><td><?= $r['date'] ?></td></tr>
            <?php endforeach; ?>
        </table>
        </div>

        <div style="display:flex; flex-wrap:wrap; gap:30px; margin-top:10px;">
            <div style="flex:1; min-width:200px;">
                <h4 style="color:#0a2a4a;">Expenses by Category</h4>
                <ul style="list-style:none; padding:0;">
                <?php foreach($catTotals as $c): ?>
                    <li style="padding:6px 0; border-bottom:1px solid #e6ecf3;"><?= htmlspecialchars($c['category_name']) ?>: <?= formatCurrency($c['total']) ?></li>
                <?php endforeach; ?>
                </ul>
            </div>
            <div style="flex:1; min-width:200px;">
                <h4 style="color:#0a2a4a;">Project Expense Summary</h4>
                <ul style="list-style:none; padding:0;">
                <?php foreach($projSummary as $p): ?>
                    <li style="padding:6px 0; border-bottom:1px solid #e6ecf3;"><?= htmlspecialchars($p['project_name']) ?>: <?= formatCurrency($p['spent'] ?? 0) ?> / <?= formatCurrency($p['budget']) ?></li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================== -->
    <!-- PROJECTS -->
    <?php if ($page == 'projects'): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h2 style="color:#0a2a4a; font-weight:normal;">Projects</h2>
            <a href="#projectModal" class="btn">+ Add Project</a>
        </div>
        <!-- search -->
        <form method="get" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="projects">
            <input type="text" name="search" placeholder="Search projects..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" style="padding:6px 18px; border-radius:30px; border:1px solid #bcc9db; width:240px;">
            <button class="btn btn-sm">Search</button>
        </form>
        <div class="table-wrap">
        <table>
            <tr><th>ID</th><th>Project</th><th>Client</th><th>Location</th><th>Budget</th><th>Start</th><th>End</th><th>Status</th><th>Actions</th></tr>
            <?php
                $search = $_GET['search'] ?? '';
                $sql = "SELECT * FROM projects WHERE project_name LIKE ? OR client_name LIKE ? ORDER BY id DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(["%$search%", "%$search%"]);
                $projects = $stmt->fetchAll();
                foreach($projects as $p):
            ?>
            <tr>
                <td><?= $p['id'] ?></td>
                <td><?= htmlspecialchars($p['project_name']) ?></td>
                <td><?= htmlspecialchars($p['client_name']) ?></td>
                <td><?= htmlspecialchars($p['location']) ?></td>
                <td><?= formatCurrency($p['budget']) ?></td>
                <td><?= $p['start_date'] ?></td>
                <td><?= $p['end_date'] ?></td>
                <td><?= htmlspecialchars($p['status']) ?></td>
                <td>
                    <a href="#projectModal&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                    <a href="?page=projects&delete_project=<?= $p['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete project?')">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <!-- Project Modal -->
        <div id="projectModal" class="modal">
            <div class="modal-box">
                <a href="#" class="close-modal">✕</a>
                <h2><?= isset($_GET['id']) ? 'Edit Project' : 'Add Project' ?></h2>
                <?php
                    $editProj = null;
                    if (isset($_GET['id'])) {
                        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id=?");
                        $stmt->execute([$_GET['id']]);
                        $editProj = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                ?>
                <form method="post">
                    <input type="hidden" name="project_id" value="<?= $editProj['id'] ?? '' ?>">
                    <div class="form-group"><label>Project Name</label><input type="text" name="project_name" class="form-control" value="<?= htmlspecialchars($editProj['project_name'] ?? '') ?>" required></div>
                    <div class="form-group"><label>Client Name</label><input type="text" name="client_name" class="form-control" value="<?= htmlspecialchars($editProj['client_name'] ?? '') ?>" required></div>
                    <div class="form-group"><label>Location</label><input type="text" name="location" class="form-control" value="<?= htmlspecialchars($editProj['location'] ?? '') ?>"></div>
                    <div class="form-group"><label>Budget (₱)</label><input type="number" step="0.01" name="budget" class="form-control" value="<?= $editProj['budget'] ?? '' ?>" required></div>
                    <div class="form-group"><label>Start Date</label><input type="date" name="start_date" class="form-control" value="<?= $editProj['start_date'] ?? '' ?>"></div>
                    <div class="form-group"><label>Expected End Date</label><input type="date" name="end_date" class="form-control" value="<?= $editProj['end_date'] ?? '' ?>"></div>
                    <div class="form-group"><label>Status</label>
                        <select name="status" class="form-control">
                            <option value="Planning" <?= ($editProj['status']??'')=='Planning'?'selected':'' ?>>Planning</option>
                            <option value="Ongoing" <?= ($editProj['status']??'')=='Ongoing'?'selected':'' ?>>Ongoing</option>
                            <option value="Completed" <?= ($editProj['status']??'')=='Completed'?'selected':'' ?>>Completed</option>
                            <option value="On Hold" <?= ($editProj['status']??'')=='On Hold'?'selected':'' ?>>On Hold</option>
                        </select>
                    </div>
                    <button type="submit" name="save_project" class="btn">Save Project</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================== -->
    <!-- EXPENSES (CORE) -->
    <?php if ($page == 'expenses'): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h2 style="color:#0a2a4a; font-weight:normal;">Expenses</h2>
            <a href="#expenseModal" class="btn">+ Add Expense</a>
        </div>
        <!-- filters -->
        <form method="get" class="filter-row">
            <input type="hidden" name="page" value="expenses">
            <input type="text" name="search" placeholder="Search..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            <select name="filter_project">
                <option value="">All Projects</option>
                <?php foreach(getProjectOptions($pdo) as $opt): ?>
                <option value="<?= $opt['id'] ?>" <?= ($_GET['filter_project']??'')==$opt['id']?'selected':'' ?>><?= htmlspecialchars($opt['project_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_category">
                <option value="">All Categories</option>
                <?php foreach(getCategoryOptions($pdo) as $opt): ?>
                <option value="<?= $opt['id'] ?>" <?= ($_GET['filter_category']??'')==$opt['id']?'selected':'' ?>><?= htmlspecialchars($opt['category_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="filter_date" value="<?= $_GET['filter_date'] ?? '' ?>">
            <button class="btn btn-sm">Filter</button>
        </form>
        <div class="table-wrap">
        <table>
            <tr><th>ID</th><th>Project</th><th>Date</th><th>Category</th><th>Description</th><th>Qty</th><th>Unit</th><th>Total</th><th>Supplier</th><th>Payment</th><th>Receipt</th><th>Actions</th></tr>
            <?php
                $search = $_GET['search'] ?? '';
                $filterProj = $_GET['filter_project'] ?? '';
                $filterCat = $_GET['filter_category'] ?? '';
                $filterDate = $_GET['filter_date'] ?? '';
                $sql = "SELECT e.*, p.project_name, c.category_name FROM expenses e 
                        JOIN projects p ON e.project_id=p.id 
                        JOIN expense_categories c ON e.category_id=c.id 
                        WHERE (e.description LIKE ? OR e.supplier LIKE ?) ";
                $params = ["%$search%", "%$search%"];
                if ($filterProj) { $sql .= " AND e.project_id = ?"; $params[] = $filterProj; }
                if ($filterCat) { $sql .= " AND e.category_id = ?"; $params[] = $filterCat; }
                if ($filterDate) { $sql .= " AND e.date = ?"; $params[] = $filterDate; }
                $sql .= " ORDER BY e.id DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $expenses = $stmt->fetchAll();
                foreach($expenses as $e):
            ?>
            <tr>
                <td><?= $e['id'] ?></td>
                <td><?= htmlspecialchars($e['project_name']) ?></td>
                <td><?= $e['date'] ?></td>
                <td><?= htmlspecialchars($e['category_name']) ?></td>
                <td><?= htmlspecialchars($e['description']) ?></td>
                <td><?= $e['quantity'] ?></td>
                <td><?= formatCurrency($e['unit_cost']) ?></td>
                <td><?= formatCurrency($e['total_cost']) ?></td>
                <td><?= htmlspecialchars($e['supplier']) ?></td>
                <td><?= htmlspecialchars($e['payment_method']) ?></td>
                <td><?= htmlspecialchars($e['receipt_reference']) ?></td>
                <td>
                    <a href="#expenseModal&id=<?= $e['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                    <a href="?page=expenses&delete_expense=<?= $e['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete expense?')">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <!-- Expense Modal -->
        <div id="expenseModal" class="modal">
            <div class="modal-box">
                <a href="#" class="close-modal">✕</a>
                <h2><?= isset($_GET['id']) ? 'Edit Expense' : 'Add Expense' ?></h2>
                <?php
                    $editExp = null;
                    if (isset($_GET['id'])) {
                        $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id=?");
                        $stmt->execute([$_GET['id']]);
                        $editExp = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                ?>
                <form method="post">
                    <input type="hidden" name="expense_id" value="<?= $editExp['id'] ?? '' ?>">
                    <div class="form-group"><label>Project</label>
                        <select name="project_id" class="form-control" required>
                            <?php foreach(getProjectOptions($pdo) as $opt): ?>
                            <option value="<?= $opt['id'] ?>" <?= ($editExp['project_id']??'')==$opt['id']?'selected':'' ?>><?= htmlspecialchars($opt['project_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Category</label>
                        <select name="category_id" class="form-control" required>
                            <?php foreach(getCategoryOptions($pdo) as $opt): ?>
                            <option value="<?= $opt['id'] ?>" <?= ($editExp['category_id']??'')==$opt['id']?'selected':'' ?>><?= htmlspecialchars($opt['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Date</label><input type="date" name="date" class="form-control" value="<?= $editExp['date'] ?? date('Y-m-d') ?>" required></div>
                    <div class="form-group"><label>Item/Description</label><input type="text" name="description" class="form-control" value="<?= htmlspecialchars($editExp['description'] ?? '') ?>" required></div>
                    <div class="form-group"><label>Quantity</label><input type="number" step="0.01" name="quantity" class="form-control" value="<?= $editExp['quantity'] ?? 1 ?>" required></div>
                    <div class="form-group"><label>Unit Cost (₱)</label><input type="number" step="0.01" name="unit_cost" class="form-control" value="<?= $editExp['unit_cost'] ?? 0 ?>" required></div>
                    <div class="form-group"><label>Supplier</label><input type="text" name="supplier" class="form-control" value="<?= htmlspecialchars($editExp['supplier'] ?? '') ?>"></div>
                    <div class="form-group"><label>Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <option value="Cash" <?= ($editExp['payment_method']??'')=='Cash'?'selected':'' ?>>Cash</option>
                            <option value="Bank Transfer" <?= ($editExp['payment_method']??'')=='Bank Transfer'?'selected':'' ?>>Bank Transfer</option>
                            <option value="Check" <?= ($editExp['payment_method']??'')=='Check'?'selected':'' ?>>Check</option>
                            <option value="Credit" <?= ($editExp['payment_method']??'')=='Credit'?'selected':'' ?>>Credit</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Receipt/Reference</label><input type="text" name="receipt_reference" class="form-control" value="<?= htmlspecialchars($editExp['receipt_reference'] ?? '') ?>"></div>
                    <button type="submit" name="save_expense" class="btn">Save Expense</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================== -->
    <!-- CATEGORIES -->
    <?php if ($page == 'categories'): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h2 style="color:#0a2a4a; font-weight:normal;">Expense Categories</h2>
            <a href="#categoryModal" class="btn">+ Add Category</a>
        </div>
        <div class="table-wrap">
        <table>
            <tr><th>ID</th><th>Category Name</th><th>Actions</th></tr>
            <?php
                $cats = $pdo->query("SELECT * FROM expense_categories ORDER BY category_name")->fetchAll();
                foreach($cats as $c):
            ?>
            <tr>
                <td><?= $c['id'] ?></td>
                <td><?= htmlspecialchars($c['category_name']) ?></td>
                <td>
                    <a href="#categoryModal&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                    <a href="?page=categories&delete_category=<?= $c['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete category?')">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <!-- Category Modal -->
        <div id="categoryModal" class="modal">
            <div class="modal-box">
                <a href="#" class="close-modal">✕</a>
                <h2><?= isset($_GET['id']) ? 'Edit Category' : 'Add Category' ?></h2>
                <?php
                    $editCat = null;
                    if (isset($_GET['id'])) {
                        $stmt = $pdo->prepare("SELECT * FROM expense_categories WHERE id=?");
                        $stmt->execute([$_GET['id']]);
                        $editCat = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                ?>
                <form method="post">
                    <input type="hidden" name="category_id" value="<?= $editCat['id'] ?? '' ?>">
                    <div class="form-group"><label>Category Name</label><input type="text" name="category_name" class="form-control" value="<?= htmlspecialchars($editCat['category_name'] ?? '') ?>" required></div>
                    <button type="submit" name="save_category" class="btn">Save Category</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================== -->
    <!-- REPORTS -->
    <?php if ($page == 'reports'): ?>
        <h2 style="color:#0a2a4a; font-weight:normal;">Reports</h2>
        <div style="display:flex; flex-wrap:wrap; gap:20px; margin:18px 0;">
            <a href="?page=reports&type=project" class="btn">Project Expense Report</a>
            <a href="?page=reports&type=monthly" class="btn">Monthly Expense Report</a>
            <a href="?page=reports&type=category" class="btn">Category Report</a>
        </div>
        <?php
            $reportType = $_GET['type'] ?? '';
            $reportData = [];
            if ($reportType == 'project') {
                $reportData = $pdo->query("SELECT p.project_name, SUM(e.total_cost) as total, p.budget FROM projects p LEFT JOIN expenses e ON p.id=e.project_id GROUP BY p.id")->fetchAll();
            } elseif ($reportType == 'monthly') {
                $reportData = $pdo->query("SELECT DATE_FORMAT(date, '%Y-%m') as month, SUM(total_cost) as total FROM expenses GROUP BY month ORDER BY month DESC")->fetchAll();
            } elseif ($reportType == 'category') {
                $reportData = $pdo->query("SELECT c.category_name, SUM(e.total_cost) as total FROM expenses e JOIN expense_categories c ON e.category_id=c.id GROUP BY c.id")->fetchAll();
            }
            if ($reportData): ?>
                <div class="table-wrap">
                <table>
                    <tr><th>#</th><th>Name</th><th>Total</th></tr>
                    <?php $i=1; foreach($reportData as $r): ?>
                    <tr><td><?= $i++ ?></td><td><?= htmlspecialchars($r[0] ?? $r['project_name'] ?? $r['month'] ?? $r['category_name'] ?? '') ?></td><td><?= formatCurrency($r['total'] ?? $r[1] ?? 0) ?></td></tr>
                    <?php endforeach; ?>
                </table>
                </div>
                <button onclick="window.print()" class="btn">🖨️ Print / Export</button>
            <?php endif; ?>
    <?php endif; ?>

    <!-- ========================================================== -->
    <!-- SETTINGS -->
    <?php if ($page == 'settings'): ?>
        <h2 style="color:#0a2a4a; font-weight:normal;">Settings</h2>
        <div style="max-width:420px;">
            <form method="post">
                <div class="form-group"><label>Company Name</label><input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($_SESSION['company_name'] ?? '') ?>"></div>
                <div class="form-group"><label>User Profile (demo)</label><input type="text" class="form-control" value="Admin" disabled></div>
                <div class="form-group"><label>Change Password</label><input type="password" class="form-control" placeholder="New password" disabled></div>
                <button class="btn" onclick="alert('Settings saved (demo).')">Save Settings</button>
                <a href="?logout=1" class="btn btn-danger" style="margin-left:10px;" onclick="return confirm('Logout?')">Logout</a>
            </form>
        </div>
    <?php endif; ?>

</div><!-- end main -->

<!-- logout handler -->
<?php if (isset($_GET['logout'])): session_destroy(); header("Location: ?page=dashboard"); exit; endif; ?>

</body>
</html>