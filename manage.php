<?php
// ======================================================================
// PPC Portal - Backend Logic (v3 with Image Optimization & Trading)
// ======================================================================
session_start();

// --- Configuration ---
define('DB_PATH', __DIR__ . '/db/ppc_database.sqlite');
define('IMAGES_DIR', __DIR__ . '/assets/images/');
define('ENTRIES_DIR', __DIR__ . '/entries/');
define('ROOT_PATH', __DIR__ . '/');
define('OWNER_NAME', 'Sujay Sreedhar'); // Your name for branding

// --- Helper Functions ---

/**
 * Get SQLite database connection
 */
function get_db() {
    try {
        $db = new SQLite3(DB_PATH);
        $db->enableExceptions(true);
        return $db;
    } catch (Exception $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

/**
 * Initialize the database and table if they don't exist
 */
function initialize_database() {
    if (!file_exists(DB_PATH)) {
        $db = get_db();
        // Schema with origin, source, and quantity
        $query = "
        CREATE TABLE IF NOT EXISTS ppc_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            location TEXT,
            type TEXT,
            region TEXT,
            year INTEGER,
            description TEXT,
            image TEXT,
            origin TEXT DEFAULT 'India',
            source TEXT,
            quantity INTEGER DEFAULT 1
        )";
        $db->exec($query);
    }
}

/**
 * Create a unique slug from a title
 */
function create_slug($title, $id = 0) {
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = preg_replace('/-+/', '-', $slug);

    $db = get_db();
    $original_slug = $slug;
    $counter = 2;

    while (true) {
        $sql = "SELECT id FROM ppc_entries WHERE slug = :slug";
        if ($id > 0) {
            $sql .= " AND id != :id";
        }
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':slug', $slug, SQLITE3_TEXT);
        if ($id > 0) {
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        if ($result->fetchArray() === false) {
            break;
        }
        $slug = $original_slug . '-' . $counter++;
    }
    return $slug;
}

/**
 * Delete an entry's associated files
 */
function delete_entry_files($entry) {
    if ($entry) {
        $htmlFile = ENTRIES_DIR . $entry['id'] . '-' . $entry['slug'] . '.html';
        if (file_exists($htmlFile)) unlink($htmlFile);
        
        if ($entry['image'] && $entry['image'] !== 'placeholder.jpg' && file_exists(IMAGES_DIR . $entry['image'])) {
            unlink(IMAGES_DIR . $entry['image']);
        }
    }
}

/**
 * Processes an uploaded image: resizes, compresses, and saves it.
 * Moves the original to a separate folder.
 *
 * @param array $file The $_FILES['image'] array.
 * @return string|false The new filename of the processed image, or false on failure.
 */
function process_uploaded_image($file) {
    // --- Configuration ---
    $target_dir = IMAGES_DIR;
    $originals_dir = $target_dir . 'originals/';
    $target_width = 1200; // Max width for web images in pixels
    $jpeg_quality = 80;   // Compression quality (0-100)

    $tmp_name = $file['tmp_name'];
    $original_name = $file['name'];
    $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    
    $new_filename = time() . '_' . uniqid() . '.jpg';
    $target_path = $target_dir . $new_filename;
    
    list($width, $height) = getimagesize($tmp_name);

    $ratio = $width / $height;
    if ($target_width / $target_width > $ratio) {
        $new_width = $target_width;
        $new_height = $target_width / $ratio;
    } else {
        $new_width = $target_width * $ratio;
        $new_height = $target_width;
    }
    if ($width < $target_width) {
        $new_width = $width;
        $new_height = $height;
    }

    $thumb = imagecreatetruecolor($new_width, $new_height);
    $source = null;
    
    if ($file_ext === 'jpg' || $file_ext === 'jpeg') {
        $source = imagecreatefromjpeg($tmp_name);
    } elseif ($file_ext === 'png') {
        $source = imagecreatefrompng($tmp_name);
        $bg = imagecreatetruecolor($new_width, $new_height);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagealphablending($bg, true);
        imagecopy($thumb, $bg, 0, 0, 0, 0, $new_width, $new_height);
        imagedestroy($bg);
    } elseif ($file_ext === 'gif') {
        $source = imagecreatefromgif($tmp_name);
    }
    
    if ($source === null) { return false; }

    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    imagejpeg($thumb, $target_path, $jpeg_quality);
    
    if (!is_dir($originals_dir)) { mkdir($originals_dir, 0755, true); }
    // Use copy + unlink for max compatibility instead of move_uploaded_file
    copy($tmp_name, $originals_dir . $original_name);
    
    imagedestroy($thumb);
    imagedestroy($source);

    return $new_filename;
}


// --- Static Site Generation Functions ---

function regenerate_all() {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM ppc_entries ORDER BY year DESC, title ASC");
    $result = $stmt->execute();
    $entries = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $entries[] = $row;
    }

    $files = glob(ENTRIES_DIR . '*.html');
    foreach($files as $file){ if(is_file($file)) unlink($file); }
    
    regenerate_json($entries);
    regenerate_index($entries);
    regenerate_entry_pages($entries);
}

function regenerate_json($entries) {
    file_put_contents(ROOT_PATH . 'ppc.json', json_encode($entries, JSON_PRETTY_PRINT));
}

function regenerate_index($entries) {
    ob_start();
    // Index page HTML and JavaScript from previous steps...
    // (This function's content remains the same as before)
    ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PPC Collection of <?= OWNER_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .card-img-top { width: 100%; height: 200px; object-fit: cover; }
        .ppc-card { transition: transform 0.2s ease-in-out; }
        .ppc-card:hover { transform: translateY(-5px); }
        .pagination .page-link { cursor: pointer; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container">
            <a class="navbar-brand" href="#"><i class="bi bi-envelope-paper-heart-fill"></i> PPC Catalog</a>
        </div>
    </nav>
    <header class="py-5 bg-light border-bottom mb-4">
        <div class="container">
            <div class="text-center my-4">
                <h1 class="fw-bolder display-5">The PPC Collection of <?= OWNER_NAME ?></h1>
                <p class="lead mb-0">A catalog of my private collection of Permanent Pictorial Cancellations.</p>
            </div>
        </div>
    </header>
    <main class="container">
        <div class="row mb-4">
            <div class="col-md-8 offset-md-2">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" id="searchInput" class="form-control form-control-lg" placeholder="Search by title, location, year, origin, source...">
                </div>
                <div id="resultsCount" class="text-center text-muted mt-2"></div>
            </div>
        </div>
        <div id="ppcGrid" class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4">
            <?php foreach ($entries as $entry): ?>
            <?php 
                $search_content = strtolower(implode(' ', [$entry['title'], $entry['location'], $entry['year'], $entry['region'], $entry['type'], $entry['origin'], $entry['source']]));
            ?>
            <div class="col ppc-card" data-search-content="<?= htmlspecialchars($search_content) ?>">
                <div class="card h-100 shadow-sm">
                    <img src="assets/images/<?= htmlspecialchars($entry['image']) ?>" class="card-img-top" alt="<?= htmlspecialchars($entry['title']) ?>">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><?= htmlspecialchars($entry['title']) ?></h5>
                        <p class="card-text text-muted small"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($entry['location']) ?></p>
                        <div class="mt-auto pt-2">
                            <span class="badge bg-primary me-1"><?= htmlspecialchars($entry['origin']) ?></span>
                            <span class="badge bg-secondary me-2"><?= htmlspecialchars($entry['year']) ?></span>
                            <a href="entries/<?= $entry['id'] . '-' . $entry['slug'] ?>.html" class="btn btn-primary btn-sm">View Details <i class="bi bi-arrow-right-short"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <nav aria-label="Page navigation" class="mt-5 d-flex justify-content-center"><ul id="pagination" class="pagination"></ul></nav>
    </main>
    <footer class="text-center py-4 bg-dark text-white mt-5"><p class="mb-0">© <?= date('Y') ?> <?= OWNER_NAME ?>. A catalog of my private collection.</p></footer>
<script>
document.addEventListener('DOMContentLoaded',function(){const e=12,t=document.getElementById("searchInput"),n=document.getElementById("ppcGrid"),a=Array.from(n.getElementsByClassName("ppc-card")),l=document.getElementById("pagination"),c=document.getElementById("resultsCount");let d=1,s=[...a];function i(t,n){d=t;const o=(t-1)*e,r=o+e;a.forEach(e=>e.style.display="none");const i=n.slice(o,r);i.forEach(e=>e.style.display="block"),u(n.length)}function o(e){l.innerHTML="";const t=Math.ceil(e.length/e);if(t<=1)return void(c.textContent=`${e.length} entr${1===e.length?"y":"ies"} found.`);c.textContent=`Showing ${Math.min(e.length,t*d)} of ${e.length} entries.`;const n=document.createElement("li");n.className=`page-item ${1===d?"disabled":""}`,n.innerHTML='<a class="page-link">Previous</a>',n.addEventListener("click",()=>{1<d&&i(d-1,e)}),l.appendChild(n);for(let a=1;a<=t;a++){const e=document.createElement("li");e.className=`page-item ${a===d?"active":""}`,e.innerHTML=`<a class="page-link">${a}</a>`,e.addEventListener("click",()=>i(a,s)),l.appendChild(e)}const o=document.createElement("li");o.className=`page-item ${d===t?"disabled":""}`,o.innerHTML='<a class="page-link">Next</a>',o.addEventListener("click",()=>{d<t&&i(d+1,e)}),l.appendChild(o)}function u(e){const t=Math.ceil(e/e),n=l.children;if(0===n.length)return;Array.from(n).forEach((e,a)=>{a>0&&a<=t&&e.classList.toggle("active",a===d)}),n[0].classList.toggle("disabled",1===d),n[n.length-1].classList.toggle("disabled",d===t)}t.addEventListener("input",function(){s=a.filter(e=>e.getAttribute("data-search-content").includes(t.value.toLowerCase().trim())),i(1,s),o(s)}),i(1,a),o(a)});
</script></body></html>
    <?php
    $html = ob_get_clean();
    file_put_contents(ROOT_PATH . 'index.html', $html);
}

function regenerate_entry_pages($entries) {
    // --- Paste your Google Form details here ---
    define('GOOGLE_FORM_BASE_URL', 'https://docs.google.com/forms/d/e/YOUR_LONG_FORM_ID/viewform');
    define('GOOGLE_FORM_ENTRY_ID', 'entry.YOUR_UNIQUE_NUMBER');
    
    foreach ($entries as $entry) {
        ob_start();

        $ppc_title_encoded = urlencode($entry['title'] . ' (Year: ' . $entry['year'] . ')');
        $trade_link = GOOGLE_FORM_BASE_URL . '?usp=pp_url&' . GOOGLE_FORM_ENTRY_ID . '=' . $ppc_title_encoded;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($entry['title']) ?> - PPC Collection of <?= OWNER_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top"><div class="container"><a class="navbar-brand" href="../index.html"><i class="bi bi-envelope-paper-heart-fill"></i> PPC Catalog</a></div></nav>
    <main class="container my-5">
        <div class="row g-5">
            <div class="col-lg-5"><img src="../assets/images/<?= htmlspecialchars($entry['image']) ?>" class="img-fluid rounded shadow-sm" alt="<?= htmlspecialchars($entry['title']) ?>" style="width: 100%; height: auto; aspect-ratio: 4/3; object-fit: cover;"></div>
            <div class="col-lg-7">
                <h1 class="display-5"><?= htmlspecialchars($entry['title']) ?></h1>
                <p class="lead text-muted"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($entry['location']) ?></p>
                <hr>
                <?php if ($entry['quantity'] > 1): ?>
                <div class="card bg-light border-success mb-4">
                    <div class="card-body text-center">
                        <h5 class="card-title">Available for Trade!</h5>
                        <p class="card-text">I have <?= htmlspecialchars($entry['quantity'] - 1) ?> extra cop<?= ($entry['quantity'] - 1) > 1 ? 'ies' : 'y' ?> of this item available.</p>
                        <a href="<?= htmlspecialchars($trade_link) ?>" class="btn btn-success" target="_blank"><i class="bi bi-arrow-repeat"></i> Make a Trade Offer</a>
                    </div>
                </div>
                <?php endif; ?>
                <h3 class="h5 mt-4">Details</h3>
                <dl class="row">
                    <dt class="col-sm-3">Origin</dt><dd class="col-sm-9"><span class="badge bg-primary"><?= htmlspecialchars($entry['origin']) ?></span></dd>
                    <dt class="col-sm-3">Year</dt><dd class="col-sm-9"><?= htmlspecialchars($entry['year']) ?></dd>
                    <dt class="col-sm-3">Region</dt><dd class="col-sm-9"><?= htmlspecialchars($entry['region']) ?></dd>
                    <dt class="col-sm-3">Type</dt><dd class="col-sm-9"><span class="badge bg-info text-dark"><?= htmlspecialchars($entry['type']) ?></span></dd>
                    <dt class="col-sm-3">In Collection</dt><dd class="col-sm-9"><?= htmlspecialchars($entry['quantity']) ?></dd>
                    <?php if (!empty($entry['source'])): ?><dt class="col-sm-3">Source</dt><dd class="col-sm-9"><?= htmlspecialchars($entry['source']) ?></dd><?php endif; ?>
                </dl>
                <h3 class="h5 mt-4">Description</h3>
                <div class="description bg-light p-3 rounded"><p class="mb-0"><?= nl2br(htmlspecialchars($entry['description'])) ?></p></div>
                <a href="../index.html" class="btn btn-secondary mt-4"><i class="bi bi-arrow-left-circle"></i> Back to Full Collection</a>
            </div>
        </div>
    </main>
    <footer class="text-center py-4 bg-body-tertiary mt-5 border-top"><p class="mb-0">© <?= date('Y') ?> <?= OWNER_NAME ?>. A catalog of my private collection.</p></footer>
</body></html>
<?php
        $html = ob_get_clean();
        $filename = ENTRIES_DIR . $entry['id'] . '-' . $entry['slug'] . '.html';
        file_put_contents($filename, $html);
    }
}


// --- Main Request Handling ---
initialize_database();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $db = get_db();

    try {
        if ($action === 'create' || $action === 'edit') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $title = trim($_POST['title'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $type = trim($_POST['type'] ?? '');
            $region = trim($_POST['region'] ?? '');
            $year = filter_input(INPUT_POST, 'year', FILTER_VALIDATE_INT);
            $description = trim($_POST['description'] ?? '');
            $origin = trim($_POST['origin'] ?? 'India');
            $source = trim($_POST['source'] ?? '');
            $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT, ['options' => ['default' => 1]]);

            if (empty($title) || empty($location) || empty($year)) {
                throw new Exception("Title, Location, and Year are required.");
            }

            // --- Image Handling (calls the optimization function) ---
            $image_name = $_POST['existing_image'] ?? 'placeholder.jpg';
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $new_image_name = process_uploaded_image($_FILES['image']);
                if ($new_image_name) {
                    if ($image_name && $image_name !== 'placeholder.jpg' && file_exists(IMAGES_DIR . $image_name)) {
                        unlink(IMAGES_DIR . $image_name);
                    }
                    $image_name = $new_image_name;
                }
            }
            
            if ($action === 'create') {
                $slug = create_slug($title);
                $stmt = $db->prepare("INSERT INTO ppc_entries (title, slug, location, type, region, year, description, image, origin, source, quantity) VALUES (:title, :slug, :location, :type, :region, :year, :description, :image, :origin, :source, :quantity)");
            } else { 
                $oldStmt = $db->prepare("SELECT slug, image FROM ppc_entries WHERE id = :id");
                $oldStmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $oldEntry = $oldStmt->execute()->fetchArray(SQLITE3_ASSOC);
                
                $slug = create_slug($title, $id);
                $stmt = $db->prepare("UPDATE ppc_entries SET title=:title, slug=:slug, location=:location, type=:type, region=:region, year=:year, description=:description, image=:image, origin=:origin, source=:source, quantity=:quantity WHERE id=:id");
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            }
            
            $stmt->bindValue(':title', $title, SQLITE3_TEXT);
            $stmt->bindValue(':slug', $slug, SQLITE3_TEXT);
            $stmt->bindValue(':location', $location, SQLITE3_TEXT);
            $stmt->bindValue(':type', $type, SQLITE3_TEXT);
            $stmt->bindValue(':region', $region, SQLITE3_TEXT);
            $stmt->bindValue(':year', $year, SQLITE3_INTEGER);
            $stmt->bindValue(':description', $description, SQLITE3_TEXT);
            $stmt->bindValue(':image', $image_name, SQLITE3_TEXT);
            $stmt->bindValue(':origin', $origin, SQLITE3_TEXT);
            $stmt->bindValue(':source', $source, SQLITE3_TEXT);
            $stmt->bindValue(':quantity', $quantity, SQLITE3_INTEGER);
            $stmt->execute();

            $_SESSION['message'] = "Entry " . ($action === 'create' ? 'created' : 'updated') . " successfully.";

        } elseif ($action === 'delete') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $stmt = $db->prepare("SELECT * FROM ppc_entries WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $entry = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            if ($entry) {
                delete_entry_files($entry);
                $stmt = $db->prepare("DELETE FROM ppc_entries WHERE id = :id");
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $stmt->execute();
                $_SESSION['message'] = "Entry deleted successfully.";
            } else {
                $_SESSION['error'] = "Entry not found.";
            }
        
        } elseif ($action === 'sync') {
            $git_commands = 'git add . && git commit -m "sync: content update" && git push';
            $output = shell_exec($git_commands . ' 2>&1');
            $_SESSION['message'] = "Sync to GitHub attempted. <br><pre>" . htmlspecialchars($output) . "</pre>";
        }

        regenerate_all();

    } catch (Exception $e) {
        $_SESSION['error'] = "An error occurred: " . $e->getMessage();
    }

    header("Location: manage.php");
    exit();
}

// --- Data for the page ---
$db = get_db();
$entries = [];
$result = $db->query("SELECT * FROM ppc_entries ORDER BY id DESC");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $entries[] = $row;
}

$edit_entry = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $id_to_edit = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $stmt = $db->prepare("SELECT * FROM ppc_entries WHERE id = :id");
    $stmt->bindValue(':id', $id_to_edit, SQLITE3_INTEGER);
    $edit_entry = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

// ======================================================================
// PPC Portal - Frontend Interface (Admin Panel)
// ======================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage PPC Catalog</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">PPC Management Portal</a>
             <form method="POST" onsubmit="return confirm('Are you sure you want to sync with GitHub? This will commit and push all changes.');">
                <input type="hidden" name="action" value="sync">
                <button type="submit" class="btn btn-success"><i class="bi bi-cloud-upload"></i> Sync to GitHub</button>
            </form>
        </div>
    </nav>
    <div class="container mt-5">
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-success" role="alert"><?= $_SESSION['message'] ?></div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger" role="alert"><?= $_SESSION['error'] ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        <div class="row">
            <div class="col-lg-12">
                <h3><?= $edit_entry ? 'Edit Entry' : 'Create New Entry' ?></h3>
                <form method="POST" enctype="multipart/form-data" class="mb-5">
                    <input type="hidden" name="action" value="<?= $edit_entry ? 'edit' : 'create' ?>">
                    <?php if ($edit_entry): ?>
                        <input type="hidden" name="id" value="<?= $edit_entry['id'] ?>">
                        <input type="hidden" name="existing_image" value="<?= $edit_entry['image'] ?>">
                    <?php endif; ?>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label for="title" class="form-label">Title</label><input type="text" class="form-control" id="title" name="title" value="<?= htmlspecialchars($edit_entry['title'] ?? '') ?>" required></div>
                        <div class="col-md-6 mb-3"><label for="location" class="form-label">Location</label><input type="text" class="form-control" id="location" name="location" value="<?= htmlspecialchars($edit_entry['location'] ?? '') ?>" required></div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3"><label for="origin" class="form-label">Origin</label><select class="form-select" id="origin" name="origin"><?php $origins = ['India', 'International']; foreach($origins as $o): ?><option value="<?= $o ?>" <?= (($edit_entry['origin'] ?? 'India') == $o) ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3 mb-3"><label for="type" class="form-label">Type</label><select class="form-select" id="type" name="type"><?php $types = ['Permanent', 'Special', 'Commemorative', 'Other']; foreach($types as $type): ?><option value="<?= $type ?>" <?= (($edit_entry['type'] ?? '') == $type) ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3 mb-3"><label for="year" class="form-label">Year</label><input type="number" class="form-control" id="year" name="year" placeholder="e.g., 2023" value="<?= htmlspecialchars($edit_entry['year'] ?? '') ?>" required></div>
                        <div class="col-md-3 mb-3"><label for="quantity" class="form-label">Number of Copies</label><input type="number" class="form-control" id="quantity" name="quantity" min="1" value="<?= htmlspecialchars($edit_entry['quantity'] ?? '1') ?>" required></div>
                    </div>
                    <div class="row">
                         <div class="col-md-6 mb-3"><label for="region" class="form-label">Region (e.g., State)</label><input type="text" class="form-control" id="region" name="region" value="<?= htmlspecialchars($edit_entry['region'] ?? '') ?>"></div>
                         <div class="col-md-6 mb-3"><label for="source" class="form-label">Source</label><input type="text" class="form-control" id="source" name="source" placeholder="e.g., eBay, GPO Bangalore, Trade" value="<?= htmlspecialchars($edit_entry['source'] ?? '') ?>"></div>
                    </div>
                    <div class="mb-3"><label for="description" class="form-label">Description</label><textarea class="form-control" id="description" name="description" rows="4"><?= htmlspecialchars($edit_entry['description'] ?? '') ?></textarea></div>
                    <div class="mb-3">
                        <label for="image" class="form-label">Image</label><input class="form-control" type="file" id="image" name="image">
                        <?php if ($edit_entry && $edit_entry['image']): ?><small class="form-text text-muted">Current: <?= $edit_entry['image'] ?>. Upload new file to replace.</small><?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $edit_entry ? 'Update Entry' : 'Create Entry' ?></button>
                    <?php if ($edit_entry): ?><a href="manage.php" class="btn btn-secondary">Cancel Edit</a><?php endif; ?>
                </form>
            </div>
        </div>
        <hr class="my-5">
        <h3>Existing Entries (<?= count($entries) ?>)</h3>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead><tr><th>ID</th><th>Image</th><th>Title</th><th>Location</th><th>Origin</th><th>Year</th><th>Qty</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td><?= $entry['id'] ?></td>
                        <td><img src="assets/images/<?= htmlspecialchars($entry['image']) ?>" alt="" width="60" height="45" style="object-fit: cover;" class="rounded"></td>
                        <td><?= htmlspecialchars($entry['title']) ?></td>
                        <td><?= htmlspecialchars($entry['location']) ?></td>
                        <td><span class="badge bg-primary"><?= htmlspecialchars($entry['origin']) ?></span></td>
                        <td><?= $entry['year'] ?></td>
                        <td><?= $entry['quantity'] ?></td>
                        <td>
                            <a href="entries/<?= $entry['id'] . '-' . $entry['slug'] ?>.html" class="btn btn-sm btn-info" title="View" target="_blank"><i class="bi bi-eye"></i></a>
                            <a href="?action=edit&id=<?= $entry['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $entry['id'] ?>"><button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="bi bi-trash"></i></button></form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>