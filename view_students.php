<?php
session_start();
include 'db.php';

// التحقق من جلسة تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html?error=auth");
    exit();
}

// قراءة نص البحث إن وجد
$search = "";
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

// إعداد الترتيب (Sorting)
$allowed_sort = array('id', 'name', 'email', 'phone', 'created_at');
$sort = isset($_GET['sort']) && in_array($_GET['sort'], $allowed_sort) ? $_GET['sort'] : 'id';
$order = isset($_GET['order']) && strtolower($_GET['order']) == 'asc' ? 'ASC' : 'DESC';
$next_order = ($order == 'ASC') ? 'desc' : 'asc';

// إعداد تقسيم الصفحات (Pagination) - 10 طلاب في كل صفحة
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// بناء شرط البحث باستخدام LIKE
$where_sql = "";
if ($search != "") {
    $s_safe = mysqli_real_escape_string($conn, $search);
    $where_sql = " WHERE name LIKE '%$s_safe%' OR email LIKE '%$s_safe%' OR phone LIKE '%$s_safe%' ";
}

// حساب العدد الكلي للطلاب المطابقين للبحث
$count_query = "SELECT COUNT(*) as total FROM students" . $where_sql;
$count_res = mysqli_query($conn, $count_query);
$count_row = mysqli_fetch_assoc($count_res);
$total_students = $count_row['total'];
$total_pages = ceil($total_students / $limit);
if ($total_pages < 1) $total_pages = 1;

// جلب سجلات الطلاب للصفحة الحالية
$sql = "SELECT * FROM students $where_sql ORDER BY $sort $order LIMIT $limit OFFSET $offset";
$result = mysqli_query($conn, $sql);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>عرض الطلاب - نظام تسجيل الطلاب</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.html">نظام تسجيل الطلاب (BWP501)</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="index.html">الرئيسية</a></li>
                    <li class="nav-item"><a class="nav-link active" href="view_students.php">عرض الطلاب</a></li>
                    <li class="nav-item"><a class="nav-link" href="add_student.php">إضافة طالب</a></li>
                    <li class="nav-item"><a class="nav-link text-warning" href="logout.php">تسجيل الخروج (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="main-box">
            <div class="row align-items-center mb-3">
                <div class="col-md-6">
                    <h3>قائمة سجلات الطلاب</h3>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="add_student.php" class="btn btn-success">+ إضافة طالب جديد</a>
                </div>
            </div>

            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
                <div class="alert alert-info">تم حذف سجل الطالب بنجاح.</div>
            <?php endif; ?>

            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'updated'): ?>
                <div class="alert alert-success">تم تحديث بيانات الطالب بنجاح.</div>
            <?php endif; ?>

            <!-- نموذج البحث -->
            <form action="view_students.php" method="GET" class="row g-2 mb-4">
                <div class="col-sm-8 col-md-9">
                    <input type="text" name="search" id="searchInput" class="form-control" 
                           placeholder="ابحث بالاسم، البريد الإلكتروني، أو رقم الهاتف..." 
                           value="<?php echo htmlspecialchars($search); ?>" onkeyup="clientSideFilter()">
                </div>
                <div class="col-sm-4 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">بحث</button>
                    <?php if ($search != ""): ?>
                        <a href="view_students.php" class="btn btn-outline-secondary">إلغاء</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- جدول الطلاب -->
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle" id="studentsTable">
                    <thead class="table-dark">
                        <tr>
                            <th><a href="?search=<?php echo urlencode($search); ?>&sort=id&order=<?php echo $next_order; ?>">#الرقم</a></th>
                            <th>الصورة</th>
                            <th><a href="?search=<?php echo urlencode($search); ?>&sort=name&order=<?php echo $next_order; ?>">الاسم</a></th>
                            <th><a href="?search=<?php echo urlencode($search); ?>&sort=email&order=<?php echo $next_order; ?>">البريد الإلكتروني</a></th>
                            <th><a href="?search=<?php echo urlencode($search); ?>&sort=phone&order=<?php echo $next_order; ?>">رقم الهاتف</a></th>
                            <th>العنوان</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && mysqli_num_rows($result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                <tr>
                                    <td><?php echo $row['id']; ?></td>
                                    <td class="text-center">
                                        <?php if (!empty($row['profile_picture']) && file_exists($row['profile_picture'])): ?>
                                            <img src="<?php echo htmlspecialchars($row['profile_picture']); ?>" alt="صورة الطالب" class="student-img">
                                        <?php else: ?>
                                            <span class="no-img-box">لا توجد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($row['address']); ?></td>
                                    <td>
                                        <a href="update_student.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-warning">تعديل</a>
                                        <a href="delete_student.php?id=<?php echo $row['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('هل أنت متأكد من حذف سجل هذا الطالب؟');">حذف</a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">لا توجد سجلات طلاب مطابقة.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- أزرار التنقل بين الصفحات (Pagination) -->
            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <div class="text-muted">
                    عرض الصفحة <?php echo $page; ?> من <?php echo $total_pages; ?> (إجمالي الطلاب: <?php echo $total_students; ?>)
                </div>

                <nav>
                    <ul class="pagination mb-0">
                        <!-- زر السابق -->
                        <li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&order=<?php echo strtolower($order); ?>">السابق</a>
                        </li>

                        <!-- أرقام الصفحات -->
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php if ($page == $i) echo 'active'; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&order=<?php echo strtolower($order); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>

                        <!-- زر التالي -->
                        <li class="page-item <?php if ($page >= $total_pages) echo 'disabled'; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&order=<?php echo strtolower($order); ?>">التالي</a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>

    <script>
        // فلترة سريعة من جهة العميل (Client-side) للصفوف المعروضة في الصفحة الحالية
        function clientSideFilter() {
            var input = document.getElementById("searchInput").value.toLowerCase();
            var table = document.getElementById("studentsTable");
            var tr = table.getElementsByTagName("tr");

            for (var i = 1; i < tr.length; i++) {
                var rowText = tr[i].innerText.toLowerCase();
                if (rowText.indexOf(input) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
