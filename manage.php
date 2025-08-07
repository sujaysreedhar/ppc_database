<?php
// ======================================================================
// PPC Portal - Backend Logic (v3.1 with Cropper.js and Fixed Table)
// ======================================================================
session_start();

// --- Configuration ---
define('DB_PATH', __DIR__ . '/db/ppc_database.sqlite');
define('IMAGES_DIR', __DIR__ . '/assets/images/');
define('ENTRIES_DIR', __DIR__ . '/entries/');
define('ROOT_PATH', __DIR__ . '/');
define('OWNER_NAME', 'Sujay Sreedhar'); // Your name for branding

// --- Helper Functions ---

function get_db() {
    try {
        $db = new SQLite3(DB_PATH);
        $db->enableExceptions(true);
        return $db;
    } catch (Exception $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

function initialize_database() {
    if (!file_exists(DB_PATH)) {
        $db = get_db();
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
        if ($id > 0) $sql .= " AND id != :id";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':slug', $slug, SQLITE3_TEXT);
        if ($id > 0) $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        if ($result->fetchArray() === false) break;
        $slug = $original_slug . '-' . $counter++;
    }
    return $slug;
}

function delete_entry_files($entry) {
    if ($entry) {
        $htmlFile = ENTRIES_DIR . $entry['id'] . '-' . $entry['slug'] . '.html';
        if (file_exists($htmlFile)) unlink($htmlFile);
        if ($entry['image'] && $entry['image'] !== 'placeholder.jpg' && file_exists(IMAGES_DIR . $entry['image'])) {
            unlink(IMAGES_DIR . $entry['image']);
        }
    }
}

function save_base64_image($base64_string) {
    if (empty($base64_string) || !str_contains($base64_string, 'base64')) return false;
    list(, $data) = explode(',', $base64_string);
    $data = base64_decode($data);
    if ($data === false) return false;
    $new_filename = time() . '_' . uniqid() . '.jpg';
    $target_path = IMAGES_DIR . $new_filename;
    return file_put_contents($target_path, $data) ? $new_filename : false;
}

function process_uploaded_image($file) { /* ... (This function is kept as a fallback, no changes needed) ... */ }

// --- Static Site Generation Functions (Using a simple Bootstrap template) ---

function regenerate_all() {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM ppc_entries ORDER BY year DESC, title ASC");
    $result = $stmt->execute();
    $entries = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $entries[] = $row;
    
    if (is_dir(ENTRIES_DIR)) {
        $files = glob(ENTRIES_DIR . '*.html');
        foreach($files as $file) if(is_file($file)) unlink($file);
    } else {
        mkdir(ENTRIES_DIR, 0755, true);
    }

    regenerate_json($entries);
    // You can replace these with your preferred template functions if you wish
     regenerate_index($entries);
     regenerate_entry_pages($entries);
}
function regenerate_entry_pages($entries) {
    // Make sure to put your own Google Form link here if you use the trade feature
    define('GOOGLE_FORM_BASE_URL', 'https://docs.google.com/forms/d/e/YOUR_LONG_FORM_ID/viewform');
    define('GOOGLE_FORM_ENTRY_ID', 'entry.YOUR_UNIQUE_NUMBER');
    
    foreach ($entries as $entry) {
        ob_start();
        $ppc_title_encoded = urlencode($entry['title'] . ' (Year: ' . $entry['year'] . ')');
        $trade_link = GOOGLE_FORM_BASE_URL . '?usp=pp_url&' . GOOGLE_FORM_ENTRY_ID . '=' . $ppc_title_encoded;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($entry['title']) ?> - PPC Collection</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark"><div class="container"><a class="navbar-brand" href="../index.html"><i class="bi bi-envelope-paper-heart-fill"></i> PPC Catalog</a></div></nav>
    <main class="container my-5">
        <div class="row g-5">
            <div class="col-lg-5"><img src="../assets/images/<?= htmlspecialchars($entry['image']) ?>" class="img-fluid rounded shadow-sm" alt="<?= htmlspecialchars($entry['title']) ?>"></div>
            <div class="col-lg-7">
                <h1 class="display-5"><?= htmlspecialchars($entry['title']) ?></h1>
                <p class="lead text-muted"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($entry['location']) ?></p>
                <hr>
                <?php if ($entry['quantity'] > 1): ?>
                <div class="card bg-light border-success mb-4">
                    <div class="card-body text-center">
                        <h5 class="card-title">Available for Trade!</h5>
                        <p class="card-text">I have <?= htmlspecialchars($entry['quantity'] - 1) ?> extra cop<?= ($entry['quantity'] - 1) > 1 ? 'ies' : 'y' ?> of this item.</p>
                        <a href="<?= htmlspecialchars($trade_link) ?>" class="btn btn-success" target="_blank"><i class="bi bi-arrow-repeat"></i> Make a Trade Offer</a>
                    </div>
                </div>
                <?php endif; ?>
                <dl class="row">
                    <dt class="col-sm-3">Origin</dt><dd class="col-sm-9"><span class="badge bg-primary"><?= htmlspecialchars($entry['origin']) ?></span></dd>
                    <dt class="col-sm-3">Year</dt><dd class="col-sm-9"><?= htmlspecialchars($entry['year']) ?></dd>
                    <dt class="col-sm-3">Region</dt><dd class="col-sm-9"><?= htmlspecialchars($entry['region']) ?></dd>
                    <dt class="col-sm-3">Type</dt><dd class="col-sm-9"><span class="badge bg-info text-dark"><?= htmlspecialchars($entry['type']) ?></span></dd>
                    <dt class="col-sm-3">In Collection</dt><dd class="col-sm-9"><?= htmlspecialchars($entry['quantity']) ?></dd>
                    <?php if (!empty($entry['source'])): ?><dt class="col-sm-3">Source</dt><dd class="col-sm-9"><?= htmlspecialchars($entry['source']) ?></dd><?php endif; ?>
                </dl>
                <h3 class="h5 mt-4">Description</h3>
                <div class="bg-light p-3 rounded"><p class="mb-0"><?= nl2br(htmlspecialchars($entry['description'])) ?></p></div>
                <a href="../index.html" class="btn btn-secondary mt-4"><i class="bi bi-arrow-left-circle"></i> Back to Full Collection</a>
            </div>
        </div>
    </main>
    <footer class="text-center py-4 bg-body-tertiary mt-5 border-top"><p class="mb-0">© <?= date('Y') ?> <?= OWNER_NAME ?></p></footer>
</body>
</html>
<?php
        $html = ob_get_clean();
        $filename = ENTRIES_DIR . $entry['id'] . '-' . $entry['slug'] . '.html';
        file_put_contents($filename, $html);
    }
}function regenerate_index($entries) {
    ob_start();
?>
<!DOCTYPE html>
<html lang="en">
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
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container"><a class="navbar-brand" href="#"><i class="bi bi-envelope-paper-heart-fill"></i> PPC Catalog</a></div>
    </nav>
    <header class="py-5 bg-light border-bottom mb-4">
        <div class="container">
            <div class="text-center my-4">
                <h1 class="fw-bolder display-5">The PPC Collection of <?= OWNER_NAME ?></h1>
                <p class="lead mb-0">A catalog of my private collection of Permanent Pictorial Cancellations.</p>
            </div>
             <div class="col-md-8 offset-md-2">
                <div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="text" id="searchInput" class="form-control form-control-lg" placeholder="Search by title, location, year, origin..."></div>
                <div id="resultsCount" class="text-center text-muted mt-2"></div>
            </div>
        </div>
    </header>
    <main class="container">
        <div id="ppcGrid" class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4">
            <?php foreach ($entries as $entry): ?>
            <?php $search_content = strtolower(implode(' ', [$entry['title'], $entry['location'], $entry['year'], $entry['region'], $entry['type'], $entry['origin'], $entry['source']])); ?>
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
    </main>
    <footer class="text-center py-4 bg-dark text-white mt-5"><p class="mb-0">© <?= date('Y') ?> <?= OWNER_NAME ?></p></footer>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const pccGrid = document.getElementById('ppcGrid');
    const allCards = Array.from(pccGrid.getElementsByClassName('ppc-card'));
    const resultsCount = document.getElementById('resultsCount');
    function handleSearch() {
        const query = searchInput.value.toLowerCase().trim();
        let visibleCount = 0;
        allCards.forEach(card => {
            const isVisible = card.getAttribute('data-search-content').includes(query);
            card.style.display = isVisible ? '' : 'none';
            if(isVisible) visibleCount++;
        });
        resultsCount.textContent = `${visibleCount} of ${allCards.length} entries shown.`;
    }
    searchInput.addEventListener('input', handleSearch);
    handleSearch(); // Initial count
});
</script>
</body>
</html>
<?php
    file_put_contents(ROOT_PATH . 'index.html', ob_get_clean());
}
function regenerate_json($entries) {
    file_put_contents(ROOT_PATH . 'ppc.json', json_encode($entries, JSON_PRETTY_PRINT));
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

            $image_name = $_POST['existing_image'] ?? 'placeholder.jpg';
            $old_image_to_delete = null;

            if (!empty($_POST['cropped_image_data'])) {
                $new_image_name = save_base64_image($_POST['cropped_image_data']);
                if ($new_image_name) {
                    if ($image_name && $image_name !== 'placeholder.jpg') {
                        $old_image_to_delete = IMAGES_DIR . $image_name;
                    }
                    $image_name = $new_image_name;
                }
            } elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                // Fallback if cropper is not used
                $new_image_name = process_uploaded_image($_FILES['image']);
                if ($new_image_name) {
                     if ($image_name && $image_name !== 'placeholder.jpg') {
                         $old_image_to_delete = IMAGES_DIR . $image_name;
                    }
                    $image_name = $new_image_name;
                }
            }
            
            if ($action === 'create') {
                $slug = create_slug($title);
                $stmt = $db->prepare("INSERT INTO ppc_entries (title, slug, location, type, region, year, description, image, origin, source, quantity) VALUES (:title, :slug, :location, :type, :region, :year, :description, :image, :origin, :source, :quantity)");
            } else { 
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
            
            if ($old_image_to_delete && file_exists($old_image_to_delete)) {
                unlink($old_image_to_delete);
            }

            $_SESSION['message'] = "Entry " . ($action === 'create' ? 'created' : 'updated') . " successfully. Regenerating site...";
            regenerate_all(); // Regenerate files after successful CUD operation

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
                $_SESSION['message'] = "Entry deleted successfully. Regenerating site...";
                regenerate_all();
            } else {
                $_SESSION['error'] = "Entry not found.";
            }
        } elseif ($action === 'sync') {
            $git_commands = 'git add . && git commit -m "sync: content update" && git push';
            $output = shell_exec($git_commands . ' 2>&1');
            $_SESSION['message'] = "Sync to GitHub attempted. <br><pre>" . htmlspecialchars($output) . "</pre>";
        }

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage PPC Catalog</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" rel="stylesheet">
    <style>
        #cropperModal .modal-lg { max-width: 900px; }
        #imageToCrop { display: block; max-width: 100%; }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">PPC Management Portal</a>
             <form method="POST" onsubmit="return confirm('Are you sure you want to sync with GitHub?');">
                <input type="hidden" name="action" value="sync">
                <button type="submit" class="btn btn-success"><i class="bi bi-cloud-upload"></i> Sync to GitHub</button>
            </form>
        </div>
    </nav>
    <div class="container mt-5">
        <?php if (isset($_SESSION['message'])): ?><div class="alert alert-success"><?= $_SESSION['message'] ?></div><?php unset($_SESSION['message']); endif; ?>
        <?php if (isset($_SESSION['error'])): ?><div class="alert alert-danger"><?= $_SESSION['error'] ?></div><?php unset($_SESSION['error']); endif; ?>
        
        <div class="card mb-5">
            <div class="card-header">
                <h3><?= $edit_entry ? 'Edit Entry' : 'Create New Entry' ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="entryForm">
                    <input type="hidden" name="action" value="<?= $edit_entry ? 'edit' : 'create' ?>">
                    <?php if ($edit_entry): ?>
                        <input type="hidden" name="id" value="<?= $edit_entry['id'] ?>">
                        <input type="hidden" name="existing_image" value="<?= $edit_entry['image'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="cropped_image_data" id="croppedImageData">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label for="title" class="form-label">Title</label><input type="text" class="form-control" id="title" name="title" value="<?= htmlspecialchars($edit_entry['title'] ?? '') ?>" required></div>
                        <div class="col-md-6 mb-3"><label for="location" class="form-label">Location</label><input type="text" class="form-control" id="location" name="location" value="<?= htmlspecialchars($edit_entry['location'] ?? '') ?>" required></div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3"><label for="origin" class="form-label">Origin</label><select class="form-select" id="origin" name="origin"><?php foreach(['India', 'International'] as $o): ?><option value="<?= $o ?>" <?= (($edit_entry['origin'] ?? 'India') == $o) ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3 mb-3"><label for="type" class="form-label">Type</label><select class="form-select" id="type" name="type"><?php foreach(['Permanent', 'Special', 'Commemorative', 'Other'] as $type): ?><option value="<?= $type ?>" <?= (($edit_entry['type'] ?? '') == $type) ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3 mb-3"><label for="year" class="form-label">Year</label><input type="number" class="form-control" id="year" name="year" placeholder="e.g., 2023" value="<?= htmlspecialchars($edit_entry['year'] ?? '') ?>" required></div>
                        <div class="col-md-3 mb-3"><label for="quantity" class="form-label">Number of Copies</label><input type="number" class="form-control" id="quantity" name="quantity" min="1" value="<?= htmlspecialchars($edit_entry['quantity'] ?? '1') ?>" required></div>
                    </div>
                    <div class="row">
                         <div class="col-md-6 mb-3"><label for="region" class="form-label">Region (e.g., State)</label><input type="text" class="form-control" id="region" name="region" value="<?= htmlspecialchars($edit_entry['region'] ?? '') ?>"></div>
                         <div class="col-md-6 mb-3"><label for="source" class="form-label">Source</label><input type="text" class="form-control" id="source" name="source" placeholder="e.g., eBay, GPO Bangalore, Trade" value="<?= htmlspecialchars($edit_entry['source'] ?? '') ?>"></div>
                    </div>
                    <div class="mb-3"><label for="description" class="form-label">Description</label><textarea class="form-control" id="description" name="description" rows="4"><?= htmlspecialchars($edit_entry['description'] ?? '') ?></textarea></div>
                    <div class="mb-3">
                        <label for="imageUpload" class="form-label">Image</label>
                        <div class="d-flex align-items-center"><input class="form-control" type="file" id="imageUpload" name="image" accept="image/*"><img id="imagePreview" src="<?= ($edit_entry && $edit_entry['image']) ? 'assets/images/' . htmlspecialchars($edit_entry['image']) : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ?>" alt="Preview" class="ms-3 rounded" style="width: 80px; height: 60px; object-fit: cover; <?= !($edit_entry && $edit_entry['image']) ? 'display: none;' : '' ?>"></div>
                        <?php if ($edit_entry && $edit_entry['image']): ?><small class="form-text text-muted">Current: <?= htmlspecialchars($edit_entry['image']) ?>. Upload new file to replace.</small><?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $edit_entry ? 'Update Entry' : 'Create Entry' ?></button>
                    <?php if ($edit_entry): ?><a href="manage.php" class="btn btn-secondary">Cancel Edit</a><?php endif; ?>
                </form>
            </div>
        </div>

        <!-- THIS IS THE FIXED SECTION -->
        <h3>Existing Entries (<?= count($entries) ?>)</h3>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr><th>ID</th><th>Image</th><th>Title</th><th>Location</th><th>Origin</th><th>Year</th><th>Qty</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td><?= $entry['id'] ?></td>
                        <td><img src="assets/images/<?= htmlspecialchars($entry['image'] ?? 'placeholder.jpg') ?>" alt="" width="60" height="45" style="object-fit: cover;" class="rounded"></td>
                        <td><?= htmlspecialchars($entry['title']) ?></td>
                        <td><?= htmlspecialchars($entry['location']) ?></td>
                        <td><span class="badge bg-primary"><?= htmlspecialchars($entry['origin']) ?></span></td>
                        <td><?= $entry['year'] ?></td>
                        <td><?= $entry['quantity'] ?></td>
                        <td>
                            <div class="btn-group">
                                <a href="entries/<?= $entry['id'] . '-' . $entry['slug'] ?>.html" class="btn btn-sm btn-info" title="View" target="_blank"><i class="bi bi-eye"></i></a>
                                <a href="?action=edit&id=<?= $entry['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this entry?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $entry['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- END OF FIXED SECTION -->

    </div>

    <div class="modal fade" id="cropperModal" tabindex="-1" aria-labelledby="cropperModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="cropperModalLabel">Crop Image</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body"><div><img id="imageToCrop" src=""></div></div>
                <div class="modal-footer">
                    <div class="me-auto d-flex align-items-center">
                        <label for="rotationSlider" class="form-label me-2 mb-0">Rotate:</label>
                        <button class="btn btn-outline-secondary btn-sm" id="rotateFineLeft" type="button"><i class="bi bi-dash-lg"></i></button>
                        <input type="range" class="form-range mx-2" id="rotationSlider" min="-45" max="45" step="1" value="0">
                        <button class="btn btn-outline-secondary btn-sm" id="rotateFineRight" type="button"><i class="bi bi-plus-lg"></i></button>
                        <span class="badge bg-secondary ms-2" id="rotationValue" style="width: 45px;">0°</span>
                        <button class="btn btn-outline-secondary btn-sm ms-2" id="rotateReset" type="button"><i class="bi bi-arrow-counterclockwise"></i></button>
                    </div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="cropButton">Crop & Save</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const imageUpload = document.getElementById('imageUpload');
        const cropperModalElement = document.getElementById('cropperModal');
        const cropperModal = new bootstrap.Modal(cropperModalElement);
        const imageToCrop = document.getElementById('imageToCrop');
        const cropButton = document.getElementById('cropButton');
        const imagePreview = document.getElementById('imagePreview');
        const croppedImageDataInput = document.getElementById('croppedImageData');
        const rotationSlider = document.getElementById('rotationSlider');
        const rotationValue = document.getElementById('rotationValue');
        const rotateFineLeft = document.getElementById('rotateFineLeft');
        const rotateFineRight = document.getElementById('rotateFineRight');
        const rotateReset = document.getElementById('rotateReset');
        let cropper;

        imageUpload.addEventListener('change', e => {
            const files = e.target.files;
            if (files && files.length > 0) {
                const reader = new FileReader();
                reader.onload = event => { imageToCrop.src = event.target.result; cropperModal.show(); };
                reader.readAsDataURL(files[0]);
            }
        });
        cropperModalElement.addEventListener('shown.bs.modal', () => {
            rotationSlider.value = 0;
            rotationValue.textContent = '0°';
            cropper = new Cropper(imageToCrop, { viewMode: 1, dragMode: 'move', background: false, autoCropArea: 0.8 });
        });
        cropperModalElement.addEventListener('hidden.bs.modal', () => {
            if (cropper) { cropper.destroy(); cropper = null; imageUpload.value = ''; }
        });
        rotationSlider.addEventListener('input', () => {
            if (cropper) { const deg = parseInt(rotationSlider.value, 10); cropper.rotateTo(deg); rotationValue.textContent = `${deg}°`; }
        });
        const adjustRotation = (amount) => {
            rotationSlider.value = parseInt(rotationSlider.value, 10) + amount;
            rotationSlider.dispatchEvent(new Event('input'));
        };
        rotateFineLeft.addEventListener('click', () => adjustRotation(-1));
        rotateFineRight.addEventListener('click', () => adjustRotation(1));
        rotateReset.addEventListener('click', () => { rotationSlider.value = 0; rotationSlider.dispatchEvent(new Event('input')); });
        cropButton.addEventListener('click', () => {
            if (!cropper) return;
            const canvas = cropper.getCroppedCanvas({ width: 1200, imageSmoothingQuality: 'high' });
            const base64data = canvas.toDataURL('image/jpeg', 0.85);
            imagePreview.src = base64data;
            imagePreview.style.display = 'block';
            croppedImageDataInput.value = base64data;
            cropperModal.hide();
        });
    });
    </script>
</body>
</html>